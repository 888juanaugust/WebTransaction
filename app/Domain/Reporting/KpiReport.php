<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Access\Role;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How the sales seats, the shops and the items are actually doing.
 *
 * The sales report answers *how much*. This one answers *how well*, which is
 * a different question and needs numbers the sales report does not carry:
 *
 * - **Cakupan** — of the toko a sales holds, how many actually bought this
 *   period. A seat can hit its omset target off three big shops while twenty
 *   others quietly stop ordering, and the omset figure alone will never say
 *   so. This is the number that does.
 * - **Jatuh tempo** — what their customers owe past the due date. Selling on
 *   credit and never collecting is not performance, and a KPI sheet that
 *   leaves collection out rewards exactly that.
 * - **Terakhir belanja / terakhir terjual** — the date something last moved.
 *   A shop that bought well in the first week and nothing since reads
 *   identically to a steady one on a monthly total.
 *
 * Two deliberate choices about *when*, because a KPI read wrong is worse than
 * no KPI at all:
 *
 * **Activity is measured over the period; debt is measured now.** Omset,
 * visits and counts all belong to the range being reviewed. Piutang does not
 * — what a customer owed on 31 August is history, and the thing worth acting
 * on is what is unpaid today. Both are labelled as such in the columns.
 *
 * **Targets only apply to a whole calendar month.** `sales_targets` is stored
 * per month; comparing a half-month of sales against a whole month's target
 * produces a number that means nothing and looks like failure. Any other
 * range leaves the target and capaian columns empty rather than guessing.
 *
 * There is no cost column anywhere here on purpose: this is the one report a
 * Sales seat should be able to read about themselves, and cost beside selling
 * price is margin.
 */
class KpiReport
{
    public function build(Period $period, KpiSubjek $subjek): ReportTable
    {
        return match ($subjek) {
            KpiSubjek::Sales => $this->perSales($period),
            KpiSubjek::Toko => $this->perToko($period),
            KpiSubjek::Barang => $this->perBarang($period),
        };
    }

    /**
     * The picture: whoever or whatever leads on the measure that matters
     * most for this subject — omset for a seat, omset for a shop, units for
     * an item. Derived from the rows already built, never re-queried.
     */
    public function chart(ReportTable $table, KpiSubjek $subjek): ReportChart
    {
        // The barang bars count units, so they must not be labelled in rupiah.
        [$judul, $key, $rupiah] = match ($subjek) {
            KpiSubjek::Sales => ['Omset per sales', 'omset', true],
            KpiSubjek::Toko => ['Toko terbesar', 'omset', true],
            KpiSubjek::Barang => ['Barang paling banyak keluar — unit', 'unit', false],
        };

        return ReportChart::topRows(
            judul: $judul,
            rows: $table->rows,
            labelKey: 'nama',
            valueKey: $key,
            rupiah: $rupiah,
        );
    }

    // ------------------------------------------------------------- per sales

    private function perSales(Period $period): ReportTable
    {
        $omset = $this->revenueBy('companies.sales_user_id', $period);
        $kunjungan = $this->visitsBySales($period);
        $piutang = $this->overdueBy('companies.sales_user_id');
        $targets = $this->targetsFor($period);

        /*
         * Every sales seat, not only the ones who sold. A seat holding twelve
         * shops and invoicing none of them is the single most important row
         * on this sheet, and a query built from invoices alone would leave it
         * out — the report would look healthy precisely when it is not.
         */
        $seats = DB::table('users')
            ->where('users.role', Role::Sales->value)
            ->where('users.is_active', true)
            ->leftJoin('companies', function ($join) {
                $join->on('companies.sales_user_id', '=', 'users.id')
                    ->where('companies.status', '=', Company::STATUS_ACTIVE);
            })
            ->selectRaw('users.id, users.name, COUNT(companies.id) AS dipegang')
            ->groupBy('users.id', 'users.name')
            ->get();

        $rows = [];

        foreach ($seats as $seat) {
            $key = (string) $seat->id;
            $sold = $omset[$key] ?? null;

            $nilai = (int) ($sold['nilai'] ?? 0);
            $faktur = (int) ($sold['faktur'] ?? 0);
            $aktif = (int) ($sold['toko'] ?? 0);
            $dipegang = (int) $seat->dipegang;
            $target = $targets[(int) $seat->id] ?? null;

            $rows[] = [
                'nama' => (string) $seat->name,
                'omset' => $nilai,
                'target' => $target,
                'capai' => $target !== null && $target > 0
                    ? round($nilai / $target * 100, 1)
                    : null,
                'toko_aktif' => $aktif,
                'toko_dipegang' => $dipegang,
                'cakupan' => $dipegang > 0 ? round($aktif / $dipegang * 100, 1) : null,
                'faktur' => $faktur,
                'rata_faktur' => $faktur > 0 ? (int) round($nilai / $faktur) : 0,
                'kunjungan' => (int) ($kunjungan[$key] ?? 0),
                'jatuh_tempo' => (int) ($piutang[$key] ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['omset'] <=> $a['omset']);

        return new ReportTable(
            judul: 'KPI sales',
            period: $period,
            columns: [
                ReportColumn::text('nama', 'Sales'),
                ReportColumn::money('omset', 'Omset'),
                ReportColumn::money('target', 'Target'),
                ReportColumn::percent('capai', 'Capai'),
                ReportColumn::number('toko_aktif', 'Toko belanja'),
                ReportColumn::number('toko_dipegang', 'Toko dipegang'),
                ReportColumn::percent('cakupan', 'Cakupan'),
                ReportColumn::number('faktur', 'Faktur'),
                ReportColumn::money('rata_faktur', 'Rata-rata/faktur'),
                ReportColumn::number('kunjungan', 'Kunjungan'),
                ReportColumn::money('jatuh_tempo', 'Jatuh tempo kini'),
            ],
            rows: $rows,
            totals: [
                'nama' => 'TOTAL',
                'omset' => array_sum(array_column($rows, 'omset')),
                'target' => array_sum(array_column($rows, 'target')),
                'toko_aktif' => array_sum(array_column($rows, 'toko_aktif')),
                'toko_dipegang' => array_sum(array_column($rows, 'toko_dipegang')),
                'faktur' => array_sum(array_column($rows, 'faktur')),
                'kunjungan' => array_sum(array_column($rows, 'kunjungan')),
                'jatuh_tempo' => array_sum(array_column($rows, 'jatuh_tempo')),
            ],
            catatan: array_values(array_filter([
                'Cakupan = toko yang benar-benar belanja dibagi toko yang dipegang. '
                .'Omset besar dengan cakupan kecil berarti bergantung pada beberapa toko saja.',
                'Jatuh tempo dihitung **hari ini**, bukan pada akhir periode — yang perlu '
                .'ditagih adalah yang belum dibayar sekarang.',
                $this->wholeMonth($period) === null
                    ? 'Target hanya muncul bila periodenya tepat satu bulan penuh, karena '
                    .'target disimpan per bulan.'
                    : null,
                'Omset mengikuti sales yang memegang pelanggan saat ini.',
            ])),
        );
    }

    // -------------------------------------------------------------- per toko

    private function perToko(Period $period): ReportTable
    {
        $omset = $this->revenueBy('companies.id', $period);
        $terakhir = $this->lastInvoiceByCompany();
        $kunjungan = $this->visitsByCompany($period);
        $piutang = $this->outstandingBy('companies.id');
        $jatuhTempo = $this->overdueBy('companies.id');

        $companies = Company::query()
            ->where('status', Company::STATUS_ACTIVE)
            ->with('salesRep')
            ->get();

        $rows = [];

        foreach ($companies as $company) {
            $key = (string) $company->id;
            $sold = $omset[$key] ?? null;

            $nilai = (int) ($sold['nilai'] ?? 0);
            $faktur = (int) ($sold['faktur'] ?? 0);

            $rows[] = [
                'nama' => $company->nama,
                'sales' => $company->salesRep?->name ?? 'Belum ada sales',
                'omset' => $nilai,
                'faktur' => $faktur,
                'rata_faktur' => $faktur > 0 ? (int) round($nilai / $faktur) : 0,
                'jenis_barang' => (int) ($sold['sku'] ?? 0),
                'terakhir' => $terakhir[$key] ?? null,
                'kunjungan' => (int) ($kunjungan[$key] ?? 0),
                'piutang' => (int) ($piutang[$key] ?? 0),
                'jatuh_tempo' => (int) ($jatuhTempo[$key] ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['omset'] <=> $a['omset']);

        return new ReportTable(
            judul: 'KPI toko',
            period: $period,
            columns: [
                ReportColumn::text('nama', 'Toko'),
                ReportColumn::text('sales', 'Sales'),
                ReportColumn::money('omset', 'Omset'),
                ReportColumn::number('faktur', 'Faktur'),
                ReportColumn::money('rata_faktur', 'Rata-rata/faktur'),
                ReportColumn::number('jenis_barang', 'Jenis barang'),
                ReportColumn::date('terakhir', 'Terakhir belanja'),
                ReportColumn::number('kunjungan', 'Kunjungan'),
                ReportColumn::money('piutang', 'Piutang kini'),
                ReportColumn::money('jatuh_tempo', 'Jatuh tempo kini'),
            ],
            rows: $rows,
            totals: [
                'nama' => 'TOTAL',
                'omset' => array_sum(array_column($rows, 'omset')),
                'faktur' => array_sum(array_column($rows, 'faktur')),
                'kunjungan' => array_sum(array_column($rows, 'kunjungan')),
                'piutang' => array_sum(array_column($rows, 'piutang')),
                'jatuh_tempo' => array_sum(array_column($rows, 'jatuh_tempo')),
            ],
            catatan: [
                'Semua toko aktif ditampilkan, termasuk yang tidak belanja sama sekali '
                .'periode ini — barisnya nol, dan itu justru yang perlu dilihat.',
                '"Terakhir belanja" dan piutang dihitung sampai hari ini, bukan sampai '
                .'akhir periode, supaya toko yang berhenti belanja langsung kelihatan.',
                'Jenis barang = berapa macam SKU yang ditebus. Toko dengan omset sama '
                .'tapi jenis barang jauh lebih sedikit lebih rapuh.',
            ],
        );
    }

    // ------------------------------------------------------------ per barang

    private function perBarang(Period $period): ReportTable
    {
        $terjual = $this->itemSales($period);
        $retur = $this->itemCredits($period);
        $stok = $this->stockOnHand();
        $deskripsi = DB::table('products')->pluck('description', 'kode');

        $rows = [];

        foreach ($terjual as $sku => $sold) {
            $unit = (int) $sold['unit'] - (int) ($retur[$sku]['unit'] ?? 0);
            $nilai = (int) $sold['nilai'] - (int) ($retur[$sku]['nilai'] ?? 0);

            $rows[] = [
                'nama' => trim($sku.' — '.(string) ($deskripsi[$sku] ?? ''), ' —'),
                'unit' => $unit,
                'omset' => $nilai,
                'toko' => (int) $sold['toko'],
                'faktur' => (int) $sold['faktur'],
                'terakhir' => $sold['terakhir'],
                'stok' => (int) ($stok[$sku] ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['unit'], $b['omset']] <=> [$a['unit'], $a['omset']]);

        return new ReportTable(
            judul: 'KPI barang',
            period: $period,
            columns: [
                ReportColumn::text('nama', 'Barang'),
                ReportColumn::number('unit', 'Unit keluar'),
                ReportColumn::money('omset', 'Omset'),
                ReportColumn::number('toko', 'Toko pembeli'),
                ReportColumn::number('faktur', 'Faktur'),
                ReportColumn::date('terakhir', 'Terakhir terjual'),
                ReportColumn::number('stok', 'Stok kini'),
            ],
            rows: $rows,
            totals: [
                'nama' => 'TOTAL',
                'unit' => array_sum(array_column($rows, 'unit')),
                'omset' => array_sum(array_column($rows, 'omset')),
                'faktur' => array_sum(array_column($rows, 'faktur')),
            ],
            catatan: [
                '"Toko pembeli" adalah jumlah toko yang berbeda, bukan jumlah faktur. '
                .'Barang yang ditebus banyak toko lebih aman distoknya daripada barang '
                .'yang laku besar di satu toko saja.',
                'Unit dan omset sudah dikurangi retur yang sudah diposting.',
                'Stok kini adalah stok saat laporan dibuka, bukan stok akhir periode.',
            ],
        );
    }

    // -------------------------------------------------------------- fixtures

    /**
     * Net invoiced revenue in the period, grouped however the caller asks.
     *
     * Same rule as the sales report — invoiced, from the line snapshots, less
     * posted credit notes — so the two screens cannot tell different stories
     * about the same month.
     *
     * @return array<string, array{nilai: int, faktur: int, toko: int, sku: int}>
     */
    private function revenueBy(string $key, Period $period): array
    {
        $sold = DB::table('invoices')
            ->whereBoundRegion('invoices')
            ->join('orders', 'invoices.order_id', '=', 'orders.id')
            ->join('order_lines', 'orders.id', '=', 'order_lines.order_id')
            ->join('companies', 'invoices.company_id', '=', 'companies.id')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->whereBetween('invoices.issued_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNotNull('order_lines.line_total_rupiah')
            ->selectRaw(
                "{$key} AS k, SUM(order_lines.line_total_rupiah) AS nilai, "
                .'COUNT(DISTINCT invoices.id) AS faktur, '
                .'COUNT(DISTINCT companies.id) AS toko, '
                .'COUNT(DISTINCT order_lines.sku) AS sku'
            )
            ->groupByRaw($key)
            ->get();

        $credited = DB::table('credit_notes')
            ->whereBoundRegion('credit_notes')
            ->join('credit_note_lines', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->join('companies', 'credit_notes.company_id', '=', 'companies.id')
            ->where('credit_notes.status', CreditNote::STATUS_POSTED)
            ->whereBetween('credit_notes.posted_at', [$period->from, $period->to])
            ->selectRaw("{$key} AS k, SUM(credit_note_lines.line_total_rupiah) AS nilai")
            ->groupByRaw($key)
            ->pluck('nilai', 'k');

        $out = [];

        foreach ($sold as $row) {
            $k = (string) $row->k;

            $out[$k] = [
                'nilai' => (int) $row->nilai - (int) ($credited[$row->k] ?? 0),
                'faktur' => (int) $row->faktur,
                'toko' => (int) $row->toko,
                'sku' => (int) $row->sku,
            ];
        }

        return $out;
    }

    /**
     * What is owed past its due date, right now.
     *
     * `debt_removals` are not netted here: a written-off invoice keeps its
     * outstanding balance until finance actually settles it, and the whole
     * point of this column is that somebody has to chase it.
     *
     * @return array<string, int>
     */
    private function overdueBy(string $key): array
    {
        return $this->outstandingQuery($key)
            ->whereDate('invoices.due_date', '<', Carbon::now()->toDateString())
            ->pluck('sisa', 'k')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return array<string, int> */
    private function outstandingBy(string $key): array
    {
        return $this->outstandingQuery($key)
            ->pluck('sisa', 'k')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Billed less paid less credited, per group — the same three terms
     * `Invoice::amountOutstanding()` uses, in one pass rather than per row.
     */
    private function outstandingQuery(string $key): Builder
    {
        return DB::table('invoices')
            ->whereBoundRegion('invoices')
            ->join('companies', 'invoices.company_id', '=', 'companies.id')
            ->where('invoices.status', Invoice::STATUS_OPEN)
            ->selectRaw(
                "{$key} AS k, SUM(invoices.total_rupiah "
                .'- COALESCE((select sum(amount_rupiah) from payment_entries '
                .'where payment_entries.invoice_id = invoices.id), 0) '
                .'- COALESCE((select sum(total_rupiah) from credit_notes '
                ."where credit_notes.invoice_id = invoices.id and credit_notes.status = '"
                .CreditNote::STATUS_POSTED."'), 0)) AS sisa"
            )
            ->groupByRaw($key);
    }

    /** @return array<string, int> */
    private function visitsBySales(Period $period): array
    {
        return DB::table('store_visits')
            ->whereBoundRegion('store_visits')
            ->whereBetween('visited_at', [$period->from, $period->to])
            ->selectRaw('sales_user_id AS k, COUNT(*) AS n')
            ->groupBy('sales_user_id')
            ->pluck('n', 'k')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return array<string, int> */
    private function visitsByCompany(Period $period): array
    {
        return DB::table('store_visits')
            ->whereBoundRegion('store_visits')
            ->whereBetween('visited_at', [$period->from, $period->to])
            ->selectRaw('company_id AS k, COUNT(*) AS n')
            ->groupBy('company_id')
            ->pluck('n', 'k')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * When each customer last bought anything — ever, not within the period.
     *
     * @return array<string, string>
     */
    private function lastInvoiceByCompany(): array
    {
        return DB::table('invoices')
            ->whereBoundRegion('invoices')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->selectRaw('company_id AS k, MAX(issued_on) AS tanggal')
            ->groupBy('company_id')
            ->pluck('tanggal', 'k')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /**
     * Units, value, buyers and the last sale, per SKU in the period.
     *
     * @return array<string, array{unit: int, nilai: int, toko: int, faktur: int, terakhir: string|null}>
     */
    private function itemSales(Period $period): array
    {
        return DB::table('invoices')
            ->whereBoundRegion('invoices')
            ->join('orders', 'invoices.order_id', '=', 'orders.id')
            ->join('order_lines', 'orders.id', '=', 'order_lines.order_id')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->whereBetween('invoices.issued_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNotNull('order_lines.line_total_rupiah')
            ->selectRaw(
                'order_lines.sku AS k, SUM(order_lines.qty_base) AS unit, '
                .'SUM(order_lines.line_total_rupiah) AS nilai, '
                .'COUNT(DISTINCT invoices.company_id) AS toko, '
                .'COUNT(DISTINCT invoices.id) AS faktur, '
                .'MAX(invoices.issued_on) AS terakhir'
            )
            ->groupBy('order_lines.sku')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->k => [
                'unit' => (int) $row->unit,
                'nilai' => (int) $row->nilai,
                'toko' => (int) $row->toko,
                'faktur' => (int) $row->faktur,
                'terakhir' => $row->terakhir === null ? null : (string) $row->terakhir,
            ]])
            ->all();
    }

    /** @return array<string, array{unit: int, nilai: int}> */
    private function itemCredits(Period $period): array
    {
        return DB::table('credit_notes')
            ->whereBoundRegion('credit_notes')
            ->join('credit_note_lines', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_notes.status', CreditNote::STATUS_POSTED)
            ->whereBetween('credit_notes.posted_at', [$period->from, $period->to])
            ->selectRaw('credit_note_lines.sku AS k, SUM(credit_note_lines.qty_base) AS unit, '
                .'SUM(credit_note_lines.line_total_rupiah) AS nilai')
            ->groupBy('credit_note_lines.sku')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->k => [
                'unit' => (int) $row->unit,
                'nilai' => (int) $row->nilai,
            ]])
            ->all();
    }

    /** @return array<string, int> */
    private function stockOnHand(): array
    {
        return DB::table('stock_levels')
            ->whereBoundRegion('stock_levels')
            ->selectRaw('sku AS k, SUM(qty_on_hand) AS n')
            ->groupBy('sku')
            ->pluck('n', 'k')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Monthly targets, but only when the period *is* that month.
     *
     * @return array<int, int>
     */
    private function targetsFor(Period $period): array
    {
        $month = $this->wholeMonth($period);

        if ($month === null) {
            return [];
        }

        return DB::table('sales_targets')
            ->where('tahun', $month->year)
            ->where('bulan', $month->month)
            ->pluck('target_rupiah', 'user_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** The month this period covers exactly, or null if it covers anything else. */
    private function wholeMonth(Period $period): ?Carbon
    {
        $isWhole = $period->from->equalTo($period->from->copy()->startOfMonth())
            && $period->to->equalTo($period->from->copy()->endOfMonth()->endOfDay());

        return $isWhole ? $period->from->copy() : null;
    }
}
