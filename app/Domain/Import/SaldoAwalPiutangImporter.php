<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * What customers already owed us on the day this system took over.
 *
 * Each row becomes an invoice — a real one, in the customer's own cabang's
 * books, that ages from its original date, appears on their statement,
 * counts against their credit limit, is chased by the collections desk and
 * is settled through `PaymentLedger` like any other. What it is *not* is a
 * sale this system made: it has no order, no lines, no PPN of its own, and
 * it carries `saldo_awal = true` so commission and the tax export leave it
 * out by name. The sales report never sees it either, because the sales
 * report joins orders.
 *
 * The journal says the same thing: Dr Piutang Usaha, Cr Saldo Awal
 * Konversi. Not Penjualan — the old books already reported that sale — and
 * not PPN Keluaran, which was paid over long ago.
 *
 * Numbers are the old system's, verbatim. That is the number the customer
 * has on their copy, and a fresh INV-SBY-… would make a remittance advice
 * unmatchable. Unique here, so the same file uploaded twice writes nothing
 * the second time.
 */
class SaldoAwalPiutangImporter
{
    use ReadsCsv;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<BarisImpor> */
    public function preview(string $contents, User $actor): array
    {
        $this->assertMayImport($actor);

        $lines = $this->lines($contents);

        if ($lines === []) {
            throw new DomainException('Berkasnya kosong.');
        }

        $header = $this->header(array_shift($lines), ['NOMOR', 'PELANGGAN', 'TANGGAL', 'SISA']);

        $existing = Invoice::query()->withoutGlobalScope('region')->pluck('nomor')->flip()->all();
        $companies = Company::query()->withoutGlobalScope('region')->get()
            ->keyBy(fn (Company $c) => strtoupper((string) $c->kode));

        $rows = [];
        $seen = [];
        $nomor = 1;

        foreach ($lines as $line) {
            $nomor++;

            if ($this->blank($line)) {
                continue;
            }

            $cells = $this->cells($line, $header);
            $alasan = [];
            $catatan = [];

            $nomorFaktur = $this->teks($cells, 'NOMOR');
            $kodePelanggan = strtoupper($this->teks($cells, 'PELANGGAN'));
            $company = $companies[$kodePelanggan] ?? null;

            if ($nomorFaktur === '') {
                $alasan[] = 'NOMOR kosong';
            } elseif (isset($existing[$nomorFaktur])) {
                $alasan[] = "NOMOR {$nomorFaktur} sudah ada di sistem";
            } elseif (isset($seen[$nomorFaktur])) {
                $alasan[] = "NOMOR {$nomorFaktur} muncul dua kali di berkas ini";
            }

            if ($kodePelanggan === '') {
                $alasan[] = 'PELANGGAN kosong';
            } elseif ($company === null) {
                $alasan[] = "PELANGGAN '{$kodePelanggan}' tidak dikenal — impor pelanggan dulu";
            }

            $tanggal = $this->tanggal($this->teks($cells, 'TANGGAL'));

            if ($tanggal === null) {
                $alasan[] = $this->teks($cells, 'TANGGAL') === '' ? 'TANGGAL kosong' : "TANGGAL '{$this->teks($cells, 'TANGGAL')}' tidak terbaca";
            } elseif ($tanggal->isFuture()) {
                $alasan[] = 'TANGGAL di masa depan — saldo awal adalah hutang yang sudah ada';
            }

            $jatuhTempoRaw = $this->teks($cells, 'JATUH_TEMPO');
            $jatuhTempo = $this->tanggal($jatuhTempoRaw);

            if ($jatuhTempoRaw !== '' && $jatuhTempo === null) {
                $alasan[] = "JATUH_TEMPO '{$jatuhTempoRaw}' tidak terbaca";
            } elseif ($jatuhTempo === null && $tanggal !== null && $company !== null) {
                $jatuhTempo = $tanggal->copy()->addDays((int) $company->payment_terms_days);
                $catatan[] = "JATUH_TEMPO kosong — dipakai tempo pelanggan, {$company->payment_terms_days} hari";
            }

            $sisa = $this->rupiah($this->teks($cells, 'SISA'));

            if ($sisa === null) {
                $alasan[] = 'SISA kosong';
            } elseif ($sisa === false) {
                $alasan[] = "SISA '{$this->teks($cells, 'SISA')}' bukan angka rupiah";
            } elseif ($sisa <= 0) {
                $alasan[] = 'SISA harus lebih dari nol — yang sudah lunas tidak perlu dibawa';
            }

            if ($nomorFaktur !== '') {
                $seen[$nomorFaktur] = true;
            }

            $rows[] = BarisImpor::dari($nomor, $nomorFaktur, $company?->nama ?? $kodePelanggan, [
                'nomor' => $nomorFaktur,
                'company' => $company,
                'tanggal' => $tanggal,
                'jatuh_tempo' => $jatuhTempo,
                'sisa' => is_int($sisa) ? $sisa : 0,
                'catatan' => $this->teks($cells, 'CATATAN'),
            ], $alasan, $catatan);
        }

        return $rows;
    }

    /** @return array{baru: int, tertahan: int, total: int} */
    public function import(string $contents, User $actor, ?string $sumber = null): array
    {
        $rows = $this->preview($contents, $actor);

        $baru = 0;
        $tertahan = 0;
        $total = 0;

        DB::transaction(function () use ($rows, $actor, $sumber, &$baru, &$tertahan, &$total) {
            foreach ($rows as $row) {
                if ($row->tertahan()) {
                    $tertahan++;

                    continue;
                }

                /** @var Company $company */
                $company = $row->nilai['company'];
                $sisa = (int) $row->nilai['sisa'];

                $invoice = new Invoice([
                    'nomor' => $row->nilai['nomor'],
                    'order_id' => null,
                    'company_id' => $company->id,
                    'npwp' => $company->npwp,
                    'nama_wajib_pajak' => $company->nama_wajib_pajak,
                    'alamat_pajak' => $company->alamat_pajak,
                    // The whole balance is what is owed; there is no sale in it
                    // to split into DPP and PPN — that split lives in the old
                    // books, where the tax was reported.
                    'subtotal_rupiah' => $sisa,
                    'discount_rupiah' => 0,
                    'dpp_rupiah' => 0,
                    'ppn_rupiah' => 0,
                    'total_rupiah' => $sisa,
                    'issued_on' => $row->nilai['tanggal'],
                    'due_date' => $row->nilai['jatuh_tempo'],
                    'saldo_awal' => true,
                ]);
                $invoice->status = Invoice::STATUS_OPEN;
                // The customer's own books, whatever cabang the importer is
                // standing in — the Owner on "Semua cabang" has none.
                $invoice->region_id = $company->region_id;
                $invoice->save();

                $this->ledger->post(
                    JournalDraft::for(
                        $invoice,
                        JournalEntry::JENIS_SALDO_AWAL,
                        "Saldo awal piutang {$invoice->nomor}",
                        $invoice->issued_on,
                    )
                        ->debit(AccountCode::PIUTANG_USAHA, $sisa, $company->nama, company: $company)
                        ->kredit(AccountCode::SALDO_AWAL, $sisa, 'Piutang dibawa dari pembukuan lama', company: $company),
                    $actor,
                );

                $baru++;
                $total += $sisa;
            }

            $this->audit->log(
                action: 'saldo_awal_piutang_diimpor',
                newValue: ['baru' => $baru, 'tertahan' => $tertahan, 'total_rupiah' => $total, 'berkas' => $sumber],
                actor: $actor,
            );
        });

        return ['baru' => $baru, 'tertahan' => $tertahan, 'total' => $total];
    }

    private function assertMayImport(User $actor): void
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new DomainException('Hanya Keuangan dan Pemilik yang bisa membawa saldo awal piutang.');
        }
    }
}
