<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\FakturExport;
use App\Models\FakturExportLine;
use App\Models\Invoice;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Preparing a month's output VAT for filing.
 *
 * Two things happen here and they are deliberately separate: `preview` says
 * what a filing would contain and what is stopping the rest, and `export`
 * writes the file and records that it was written. Anyone about to file a
 * month should see the second list before producing the first file — an
 * invoice silently left out is a sale we collected PPN on and did not report.
 *
 * **Nothing is recomputed.** Every figure comes from the invoice and its order
 * line snapshots, taken when the price was locked. The tax calculator is not
 * called: it would give the same answer today, but "would give the same
 * answer" is an assumption with a rate change in it, and the customer is
 * holding a printed faktur that says what it says.
 */
class FakturExporter
{
    public function __construct(
        private readonly FakturWriter $writer,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What a filing for this period would contain, and what it would leave out.
     */
    public function preview(int $tahun, int $masa, bool $termasukSudahDiekspor = false): FakturExportPreview
    {
        $invoices = $this->eligible($tahun, $masa, $termasukSudahDiekspor);

        $siap = [];
        $terhalang = [];

        foreach ($invoices as $invoice) {
            $blockers = FakturBlocker::forInvoice($invoice);

            if ($blockers === []) {
                $siap[] = FakturRecord::fromInvoice($invoice);
            } else {
                $terhalang = [...$terhalang, ...$blockers];
            }
        }

        return new FakturExportPreview(
            tahun: $tahun,
            masa: $masa,
            siap: $siap,
            terhalang: $terhalang,
            sudahDiekspor: $this->alreadyExportedCount($tahun, $masa),
        );
    }

    /**
     * Write the file and record the filing.
     *
     * Blocked invoices are left out rather than fixed up. The caller has
     * already been shown them by `preview`, and quietly filing an incomplete
     * month while reporting success is the failure this whole class is shaped
     * to avoid — so the count that went in is on the returned record and on
     * the screen, next to the count that did not.
     */
    public function export(
        int $tahun,
        int $masa,
        User $actor,
        bool $termasukSudahDiekspor = false,
        ?string $catatan = null,
    ): FakturExport {
        if (! $actor->role()->canExportFaktur()) {
            throw new DomainException('Anda tidak berhak mengekspor faktur pajak.');
        }

        $preview = $this->preview($tahun, $masa, $termasukSudahDiekspor);

        if ($preview->siap === []) {
            throw new DomainException(
                $preview->terhalang === []
                    ? "Tidak ada faktur untuk masa pajak {$masa}/{$tahun}."
                    : "Semua faktur pada masa pajak {$masa}/{$tahun} terhalang. Perbaiki dulu datanya."
            );
        }

        $contents = $this->writer->write($preview->siap);

        return DB::transaction(function () use ($preview, $contents, $tahun, $masa, $actor, $catatan) {
            $export = FakturExport::create([
                'nomor' => $this->numbers->nextFakturExportNumber(),
                'masa_pajak' => $masa,
                'tahun_pajak' => $tahun,
                'format' => $this->writer->format(),
                'jumlah_faktur' => count($preview->siap),
                'total_dpp_rupiah' => $preview->totalDpp(),
                'total_ppn_rupiah' => $preview->totalPpn(),
                'catatan' => $catatan,
                'created_by' => $actor->id,
            ]);

            /*
             * Stored before the invoices are stamped, so a write failure means
             * nothing was reported rather than a month marked as filed with no
             * file behind it. Kept forever: what was actually handed over
             * matters more than what we could regenerate, because regenerating
             * it next year would use whatever the code does by then.
             */
            $path = sprintf(
                'faktur-pajak/%04d/%02d/%s.%s',
                $tahun, $masa, $export->nomor, $this->writer->extension(),
            );

            Storage::disk('local')->put($path, $contents);

            $export->forceFill(['file_path' => $path])->save();

            $referensi = array_map(fn (FakturRecord $r) => $r->referensi, $preview->siap);

            $invoices = Invoice::query()->whereIn('nomor', $referensi)->get();

            foreach ($invoices as $invoice) {
                FakturExportLine::create([
                    'faktur_export_id' => $export->id,
                    'invoice_id' => $invoice->id,
                    'referensi' => $invoice->nomor,
                ]);

                $invoice->forceFill(['faktur_exported_at' => now()])->save();
            }

            $this->audit->log(
                action: 'faktur_exported',
                subject: $export,
                newValue: [
                    'nomor' => $export->nomor,
                    'masa_pajak' => $masa,
                    'tahun_pajak' => $tahun,
                    'format' => $export->format,
                    'jumlah_faktur' => $export->jumlah_faktur,
                    'total_ppn_rupiah' => $export->total_ppn_rupiah,
                    'terhalang' => count($preview->terhalang),
                ],
                actor: $actor,
                alasan: $catatan,
            );

            return $export->refresh();
        });
    }

    /** The file as it was written, or null if it is gone from disk. */
    public function contents(FakturExport $export): ?string
    {
        if ($export->file_path === null || ! Storage::disk('local')->exists($export->file_path)) {
            return null;
        }

        return Storage::disk('local')->get($export->file_path);
    }

    /**
     * Invoices belonging to this tax period.
     *
     * Voided ones are excluded: there is nothing to report on a sale that was
     * cancelled. Paid and unpaid are both in — PPN is due on the sale, not on
     * the money, and waiting for payment to report a faktur would be late.
     *
     * The period follows `issued_on`, which is the faktur date, and never the
     * export date.
     *
     * @return Collection<int, Invoice>
     */
    private function eligible(int $tahun, int $masa, bool $termasukSudahDiekspor): Collection
    {
        $query = Invoice::query()
            ->with(['order.lines', 'company'])
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereYear('issued_on', $tahun)
            ->whereMonth('issued_on', $masa)
            ->orderBy('issued_on')
            ->orderBy('id');

        if (! $termasukSudahDiekspor) {
            $query->whereNull('faktur_exported_at');
        }

        return $query->get();
    }

    private function alreadyExportedCount(int $tahun, int $masa): int
    {
        return Invoice::query()
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->whereYear('issued_on', $tahun)
            ->whereMonth('issued_on', $masa)
            ->whereNotNull('faktur_exported_at')
            ->count();
    }

    /** Periods with something in them, newest first, for the screen's picker. */
    public function availablePeriods(int $limit = 24): array
    {
        return Invoice::query()
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->orderByDesc('issued_on')
            ->limit(2000)
            ->pluck('issued_on')
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m'))
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }
}
