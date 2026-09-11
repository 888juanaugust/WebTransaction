<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Audit\AuditLogger;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * What we already owed suppliers on the day this system took over — the
 * mirror of `SaldoAwalPiutangImporter`, and the same rules for the same
 * reasons.
 *
 * Each row becomes a supplier bill with no purchase order, no receipt and
 * no lines, posted and open, that ages and gets paid through
 * `SupplierLedger` like any other. Dr Saldo Awal Konversi, Cr Utang Usaha:
 * not Persediaan, because the goods are already on the shelf and were
 * valued when the stock was counted in, and not PPN Masukan, which the old
 * books already credited.
 */
class SaldoAwalHutangImporter
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

        $header = $this->header(array_shift($lines), ['NOMOR', 'PEMASOK', 'TANGGAL', 'SISA']);

        $existing = SupplierBill::query()->withoutGlobalScope('region')
            ->pluck('nomor_faktur_supplier')->flip()->all();
        $suppliers = Supplier::query()->withoutGlobalScope('region')->get()
            ->keyBy(fn (Supplier $s) => strtoupper((string) $s->kode));

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

            $nomorTagihan = $this->teks($cells, 'NOMOR');
            $kodePemasok = strtoupper($this->teks($cells, 'PEMASOK'));
            $supplier = $suppliers[$kodePemasok] ?? null;

            if ($nomorTagihan === '') {
                $alasan[] = 'NOMOR kosong';
            } elseif (isset($existing[$nomorTagihan])) {
                $alasan[] = "NOMOR {$nomorTagihan} sudah ada di sistem";
            } elseif (isset($seen[$nomorTagihan])) {
                $alasan[] = "NOMOR {$nomorTagihan} muncul dua kali di berkas ini";
            }

            if ($kodePemasok === '') {
                $alasan[] = 'PEMASOK kosong';
            } elseif ($supplier === null) {
                $alasan[] = "PEMASOK '{$kodePemasok}' tidak dikenal";
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
            } elseif ($jatuhTempo === null && $tanggal !== null && $supplier !== null) {
                $jatuhTempo = $tanggal->copy()->addDays((int) $supplier->payment_terms_days);
                $catatan[] = "JATUH_TEMPO kosong — dipakai tempo pemasok, {$supplier->payment_terms_days} hari";
            }

            $sisa = $this->rupiah($this->teks($cells, 'SISA'));

            if ($sisa === null) {
                $alasan[] = 'SISA kosong';
            } elseif ($sisa === false) {
                $alasan[] = "SISA '{$this->teks($cells, 'SISA')}' bukan angka rupiah";
            } elseif ($sisa <= 0) {
                $alasan[] = 'SISA harus lebih dari nol — yang sudah lunas tidak perlu dibawa';
            }

            if ($nomorTagihan !== '') {
                $seen[$nomorTagihan] = true;
            }

            $rows[] = BarisImpor::dari($nomor, $nomorTagihan, $supplier?->nama ?? $kodePemasok, [
                'nomor' => $nomorTagihan,
                'supplier' => $supplier,
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

                /** @var Supplier $supplier */
                $supplier = $row->nilai['supplier'];
                $sisa = (int) $row->nilai['sisa'];

                $bill = new SupplierBill([
                    // Our own number stays the internal one; the supplier's
                    // number is what their statement will quote.
                    'nomor' => 'SA-'.strtoupper(substr(md5($row->nilai['nomor']), 0, 8)),
                    'supplier_id' => $supplier->id,
                    'purchase_order_id' => null,
                    'nomor_faktur_supplier' => $row->nilai['nomor'],
                    'tanggal_faktur' => $row->nilai['tanggal'],
                    'due_date' => $row->nilai['jatuh_tempo'],
                    'catatan' => trim('Saldo awal dari pembukuan lama. '.$row->nilai['catatan']),
                    'created_by' => $actor->id,
                    'saldo_awal' => true,
                ]);
                $bill->subtotal_rupiah = $sisa;
                $bill->discount_rupiah = 0;
                $bill->dpp_rupiah = 0;
                $bill->ppn_rupiah = 0;
                $bill->total_rupiah = $sisa;
                $bill->status = SupplierBill::STATUS_OPEN;
                $bill->posted_by = $actor->id;
                $bill->posted_at = now();
                $bill->region_id = $supplier->region_id;
                $bill->save();

                $this->ledger->post(
                    JournalDraft::for(
                        $bill,
                        JournalEntry::JENIS_SALDO_AWAL,
                        "Saldo awal hutang {$bill->nomor_faktur_supplier}",
                        $bill->tanggal_faktur,
                    )
                        ->debit(AccountCode::SALDO_AWAL, $sisa, 'Hutang dibawa dari pembukuan lama', supplier: $supplier)
                        ->kredit(AccountCode::UTANG_USAHA, $sisa, $supplier->nama, supplier: $supplier),
                    $actor,
                );

                $baru++;
                $total += $sisa;
            }

            $this->audit->log(
                action: 'saldo_awal_hutang_diimpor',
                newValue: ['baru' => $baru, 'tertahan' => $tertahan, 'total_rupiah' => $total, 'berkas' => $sumber],
                actor: $actor,
            );
        });

        return ['baru' => $baru, 'tertahan' => $tertahan, 'total' => $total];
    }

    private function assertMayImport(User $actor): void
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new DomainException('Hanya Keuangan dan Pemilik yang bisa membawa saldo awal hutang.');
        }
    }
}
