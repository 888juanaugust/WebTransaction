<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Purchasing\SupplierLedger;
use App\Models\Giro;
use App\Models\PurchaseReturn;
use App\Models\SupplierBill;
use App\Models\SupplierCreditNote;
use App\Models\SupplierPaymentEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Whom we owe, and how urgently — the payables mirror of ReceivablesAgeing.
 *
 * The ageing report on the sell side answers "who gets a phone call"; this
 * one answers "who gets paid this week", which is the question Finance sits
 * down with every Monday. Same construction, same discipline: **the total
 * ties to Utang Usaha** — SupplierLedger::totalPayable(), the figure the
 * control account must equal — because a payment plan built on a number
 * that disagrees with the books is a plan for paying the wrong people.
 *
 * Four things reduce a payable without belonging to any age band, and each
 * gets its own signed column rather than being guessed into a bucket:
 * payments not attached to a bill, posted purchase returns (linked to the
 * receipt, not the bill), credit notes without a bill, and our own giro
 * that suppliers hold — committed to a date, off Utang Usaha, still owed.
 */
class PayablesAgeing
{
    /** Bucket edges, in days past due — same bands as the sell side. */
    private const BUCKETS = [30, 60, 90];

    public function __construct(
        private readonly SupplierLedger $ledger,
    ) {}

    public function build(?Carbon $asOf = null): ReportTable
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->endOfDay();

        $rows = [];

        $blank = fn (string $nama) => [
            'dimensi' => $nama,
            'belum_jatuh_tempo' => 0, 'b1' => 0, 'b2' => 0, 'b3' => 0, 'b4' => 0,
            'belum_terkait' => 0, 'retur_nota' => 0, 'giro_beredar' => 0,
            'total' => 0, 'tertua' => null,
        ];

        foreach ($this->openBills() as $bill) {
            $supplierId = (int) $bill->supplier_id;
            $rows[$supplierId] ??= $blank((string) $bill->nama);

            $outstanding = (int) $bill->total_rupiah
                - (int) $bill->dibayar
                - (int) $bill->dikredit;

            if ($outstanding === 0) {
                continue;
            }

            $due = Carbon::parse($bill->due_date)->endOfDay();
            $daysLate = $due->greaterThanOrEqualTo($asOf) ? 0 : (int) $due->diffInDays($asOf);

            $rows[$supplierId][$this->bucketFor($daysLate)] += $outstanding;
            $rows[$supplierId]['total'] += $outstanding;

            if ($daysLate > 0
                && ($rows[$supplierId]['tertua'] === null || $daysLate > $rows[$supplierId]['tertua'])) {
                $rows[$supplierId]['tertua'] = $daysLate;
            }
        }

        // Money already sent that nobody attached to a bill.
        foreach ($this->unmatched() as $supplierId => $item) {
            $rows[$supplierId] ??= $blank($item['nama']);
            $rows[$supplierId]['belum_terkait'] -= $item['nilai'];
            $rows[$supplierId]['total'] -= $item['nilai'];
        }

        /*
         * Returns and bill-less credit notes: debt reduced with no money
         * moving and no bill touched — a return hangs off the goods receipt,
         * so it can never net inside a bucket, and pretending it belongs to
         * one bill would be inventing a fact.
         */
        foreach ($this->returnsAndCredits() as $supplierId => $item) {
            $rows[$supplierId] ??= $blank($item['nama']);
            $rows[$supplierId]['retur_nota'] -= $item['nilai'];
            $rows[$supplierId]['total'] -= $item['nilai'];
        }

        // Our paper in their drawer: off Utang Usaha, committed to a date.
        foreach ($this->giroBeredar() as $supplierId => $item) {
            $rows[$supplierId] ??= $blank($item['nama']);
            $rows[$supplierId]['giro_beredar'] -= $item['nilai'];
            $rows[$supplierId]['total'] -= $item['nilai'];
        }

        $rows = array_values(array_filter($rows, fn (array $row) => $row['total'] !== 0));

        // Biggest debt first: the order the payment run works down.
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return new ReportTable(
            judul: 'Umur hutang',
            period: Period::asOf($asOf),
            columns: $this->columns(),
            rows: $rows,
            totals: $this->totals($rows),
            catatan: $this->caveats($rows),
        );
    }

    /** Same shape as the sell side's chart: totals by band, clamped for bars. */
    public function chart(ReportTable $table): ReportChart
    {
        $buckets = $this->bucketTotals($table);

        return new ReportChart(
            judul: 'Hutang per umur',
            labels: array_column($buckets, 'label'),
            values: array_map(fn ($b) => max(0, $b['nilai']), $buckets),
            catatan: 'Kolom minus (pembayaran belum terkait, retur, giro) ditampilkan positif.',
        );
    }

    /**
     * Signed bucket totals whose sum is exactly Utang Usaha — the payables
     * twin of ReceivablesAgeing::bucketTotals(), for any screen that shows
     * the bands beside the balance.
     *
     * @return list<array{label: string, nilai: int}>
     */
    public function bucketTotals(ReportTable $table): array
    {
        $buckets = [
            'belum_jatuh_tempo', 'b1', 'b2', 'b3', 'b4',
            'belum_terkait', 'retur_nota', 'giro_beredar',
        ];

        $rows = [];

        foreach ($table->columns as $column) {
            if (in_array($column->key, $buckets, true)) {
                $rows[] = [
                    'label' => $column->label,
                    'nilai' => (int) ($table->totals[$column->key] ?? 0),
                ];
            }
        }

        return $rows;
    }

    /**
     * Open bills with their bill-linked payments and credit notes already
     * netted, one query — the same sub-select shape as the sell side, and
     * for the same reason: a report that runs two queries per row is a
     * report nobody opens twice.
     */
    private function openBills()
    {
        $paid = DB::table('supplier_payment_entries')
            ->whereBoundRegion('supplier_payment_entries')
            ->selectRaw('COALESCE(SUM(amount_rupiah), 0)')
            ->whereColumn('supplier_payment_entries.supplier_bill_id', 'supplier_bills.id');

        $credited = DB::table('supplier_credit_notes')
            ->whereBoundRegion('supplier_credit_notes')
            ->selectRaw('COALESCE(SUM(total_rupiah), 0)')
            ->whereColumn('supplier_credit_notes.supplier_bill_id', 'supplier_bills.id')
            ->where('supplier_credit_notes.status', SupplierCreditNote::STATUS_POSTED);

        return DB::table('supplier_bills')
            ->whereBoundRegion('supplier_bills')
            ->join('suppliers', 'supplier_bills.supplier_id', '=', 'suppliers.id')
            ->where('supplier_bills.status', '!=', SupplierBill::STATUS_VOID)
            ->select('supplier_bills.id', 'supplier_bills.supplier_id', 'supplier_bills.total_rupiah',
                'supplier_bills.due_date', 'suppliers.nama')
            ->selectSub($paid, 'dibayar')
            ->selectSub($credited, 'dikredit')
            ->get();
    }

    /** @return array<int, array{nama: string, nilai: int}> */
    private function unmatched(): array
    {
        return SupplierPaymentEntry::query()
            ->whereNull('supplier_bill_id')
            ->join('suppliers', 'supplier_payment_entries.supplier_id', '=', 'suppliers.id')
            ->groupBy('supplier_payment_entries.supplier_id', 'suppliers.nama')
            ->selectRaw('supplier_payment_entries.supplier_id AS sid, suppliers.nama, SUM(amount_rupiah) AS nilai')
            ->get()
            ->filter(fn ($r) => (int) $r->nilai !== 0)
            ->mapWithKeys(fn ($r) => [(int) $r->sid => ['nama' => (string) $r->nama, 'nilai' => (int) $r->nilai]])
            ->all();
    }

    /** @return array<int, array{nama: string, nilai: int}> */
    private function returnsAndCredits(): array
    {
        $returns = PurchaseReturn::query()
            ->posted()
            ->join('suppliers', 'purchase_returns.supplier_id', '=', 'suppliers.id')
            ->groupBy('purchase_returns.supplier_id', 'suppliers.nama')
            ->selectRaw('purchase_returns.supplier_id AS sid, suppliers.nama, SUM(purchase_returns.total_rupiah) AS nilai')
            ->get();

        $credits = SupplierCreditNote::query()
            ->posted()
            ->whereNull('supplier_bill_id')
            ->join('suppliers', 'supplier_credit_notes.supplier_id', '=', 'suppliers.id')
            ->groupBy('supplier_credit_notes.supplier_id', 'suppliers.nama')
            ->selectRaw('supplier_credit_notes.supplier_id AS sid, suppliers.nama, SUM(supplier_credit_notes.total_rupiah) AS nilai')
            ->get();

        $out = [];

        foreach ([...$returns, ...$credits] as $r) {
            $sid = (int) $r->sid;
            $out[$sid] ??= ['nama' => (string) $r->nama, 'nilai' => 0];
            $out[$sid]['nilai'] += (int) $r->nilai;
        }

        return array_filter($out, fn ($item) => $item['nilai'] !== 0);
    }

    /** @return array<int, array{nama: string, nilai: int}> */
    private function giroBeredar(): array
    {
        return Giro::query()
            ->open()->keluar()
            ->join('suppliers', 'giros.supplier_id', '=', 'suppliers.id')
            ->groupBy('giros.supplier_id', 'suppliers.nama')
            ->selectRaw('giros.supplier_id AS sid, suppliers.nama, SUM(nilai_rupiah) AS nilai')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->sid => ['nama' => (string) $r->nama, 'nilai' => (int) $r->nilai]])
            ->all();
    }

    private function bucketFor(int $daysLate): string
    {
        if ($daysLate === 0) {
            return 'belum_jatuh_tempo';
        }

        foreach (self::BUCKETS as $i => $edge) {
            if ($daysLate <= $edge) {
                return 'b'.($i + 1);
            }
        }

        return 'b4';
    }

    /** @return list<ReportColumn> */
    private function columns(): array
    {
        return [
            ReportColumn::text('dimensi', 'Pemasok'),
            ReportColumn::money('belum_jatuh_tempo', 'Belum jatuh tempo'),
            ReportColumn::money('b1', '1–30 hari'),
            ReportColumn::money('b2', '31–60 hari'),
            ReportColumn::money('b3', '61–90 hari'),
            ReportColumn::money('b4', '> 90 hari'),
            ReportColumn::money('belum_terkait', 'Belum terkait'),
            ReportColumn::money('retur_nota', 'Retur & nota'),
            ReportColumn::money('giro_beredar', 'Giro beredar'),
            ReportColumn::money('total', 'Total'),
            ReportColumn::number('tertua', 'Tertua (hari)'),
        ];
    }

    private function totals(array $rows): array
    {
        $totals = ['dimensi' => 'TOTAL', 'tertua' => null];

        foreach (['belum_jatuh_tempo', 'b1', 'b2', 'b3', 'b4',
            'belum_terkait', 'retur_nota', 'giro_beredar', 'total'] as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }

        return $totals;
    }

    /**
     * The self-check: this table must sum to what Utang Usaha carries, and
     * when it does not, the report says so in itself rather than letting a
     * payment run go out on a figure the books dispute.
     *
     * @return list<string>
     */
    private function caveats(array $rows): array
    {
        $table = array_sum(array_column($rows, 'total'));
        $ledger = $this->ledger->totalPayable();

        if ($table === $ledger) {
            return [];
        }

        return [sprintf(
            'PERIKSA: total laporan (%s) tidak sama dengan Utang Usaha (%s). '
            .'Selisih %s — jangan jalankan pembayaran sebelum ini dijelaskan.',
            number_format($table, 0, ',', '.'),
            number_format($ledger, 0, ',', '.'),
            number_format($table - $ledger, 0, ',', '.'),
        )];
    }
}
