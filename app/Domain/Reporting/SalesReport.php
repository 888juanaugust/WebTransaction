<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Stock\MovementReason;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * What we sold, and what it made.
 *
 * Two decisions shape this, and both are about agreeing with something else.
 *
 * **Revenue is invoiced revenue.** The ledger credits Penjualan when an
 * invoice is issued, so that is what this counts: the line snapshots behind
 * invoices issued in the period, less credit notes posted in it. Counting
 * shipped goods instead would give a defensible figure that disagrees with the
 * profit and loss, and a sales report that does not reconcile to the accounts
 * is one nobody can use in an argument.
 *
 * **Cost follows its own sale, not the calendar.** An order is invoiced at
 * `awaiting_payment` and ships later, so a period's invoices and a period's
 * shipments are not the same goods — the mismatch is written up in
 * `docs/MAP.md` as a policy question. Rather than pair a month's revenue with
 * an unrelated month's cost, each invoice is matched to the COGS frozen on
 * *its own* order's shipment, whenever that happened. Margin per customer then
 * means something.
 *
 * The price of that choice is honest and reported: an invoice whose order has
 * not shipped has no cost yet, so its margin is overstated. The report counts
 * those lines and says so rather than quietly flattering the figure.
 *
 * Nothing here recomputes a price or a cost. Revenue comes from the line
 * snapshots taken at `confirmed`; cost comes from the value written onto the
 * stock movement when the goods left.
 */
class SalesReport
{
    public function build(Period $period, SalesDimension $dimension, bool $withCost = true): ReportTable
    {
        $revenue = $this->revenue($period, $dimension);
        $credits = $this->credits($period, $dimension);
        $cost = $withCost ? $this->cost($period, $dimension) : [];

        $keys = array_unique([...array_keys($revenue), ...array_keys($credits), ...array_keys($cost)]);

        $rows = [];

        foreach ($keys as $key) {
            $gross = (int) ($revenue[$key]['nilai'] ?? 0);
            $credited = (int) ($credits[$key] ?? 0);
            $net = $gross - $credited;
            $hpp = (int) ($cost[$key] ?? 0);

            $rows[] = [
                'dimensi' => (string) ($revenue[$key]['label'] ?? $key),
                'faktur' => (int) ($revenue[$key]['jumlah'] ?? 0),
                'penjualan' => $net,
                'hpp' => $withCost ? $hpp : null,
                'margin' => $withCost ? $net - $hpp : null,
                // Guarded against a dimension whose credits exceeded its sales
                // — a return in one month against an invoice from another.
                'margin_persen' => $withCost && $net !== 0
                    ? round(($net - $hpp) / $net * 100, 2)
                    : null,
            ];
        }

        // Biggest first, because the question is nearly always "who matters".
        // Except by month, which only reads in order.
        $dimension === SalesDimension::Bulan
            ? usort($rows, fn ($a, $b) => strcmp($a['dimensi'], $b['dimensi']))
            : usort($rows, fn ($a, $b) => $b['penjualan'] <=> $a['penjualan']);

        return new ReportTable(
            judul: 'Penjualan per '.strtolower($dimension->label()),
            period: $period,
            columns: $this->columns($dimension, $withCost),
            rows: $rows,
            totals: $this->totals($rows, $withCost),
            catatan: $this->caveats($period, $dimension, $withCost),
        );
    }

    /**
     * The table's picture: who matters, or the shape of the year.
     *
     * Derived from the rows already built — same figures, same filters — so
     * the bars can never disagree with the table under them. By month the
     * chart keeps the calendar's order; every other dimension shows the
     * biggest first.
     */
    public function chart(ReportTable $table, SalesDimension $dimension): ReportChart
    {
        if ($dimension === SalesDimension::Bulan) {
            return new ReportChart(
                judul: 'Penjualan per bulan',
                labels: array_column($table->rows, 'dimensi'),
                values: array_map(fn ($r) => max(0, (int) $r['penjualan']), $table->rows),
            );
        }

        return ReportChart::topRows(
            judul: $table->judul.' — terbesar',
            rows: $table->rows,
            labelKey: 'dimensi',
            valueKey: 'penjualan',
        );
    }

    /**
     * Sales per region for the period — the one chart with its own query.
     *
     * Regions are not a dimension of the table (a pinned reader's table is
     * one region by construction), so this reads across every region's books
     * deliberately. Shown only to viewers who already see all regions; the
     * page is the gate.
     */
    public function regionChart(Period $period): ReportChart
    {
        $rows = DB::table('invoices')
            ->join('regions', 'invoices.region_id', '=', 'regions.id')
            ->join('orders', 'invoices.order_id', '=', 'orders.id')
            ->join('order_lines', 'orders.id', '=', 'order_lines.order_id')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->whereBetween('invoices.issued_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNotNull('order_lines.line_total_rupiah')
            ->selectRaw('regions.kode AS kode, SUM(order_lines.line_total_rupiah) AS nilai')
            ->groupBy('regions.kode')
            ->orderByDesc('nilai')
            ->get();

        return new ReportChart(
            judul: 'Penjualan per wilayah',
            labels: $rows->pluck('kode')->map(fn ($k) => (string) $k)->all(),
            values: $rows->pluck('nilai')->map(fn ($v) => (int) $v)->all(),
            catatan: 'Semua wilayah, sebelum nota kredit.',
        );
    }

    /**
     * Invoiced revenue, from the order line snapshots behind each invoice.
     *
     * `line_total_rupiah` is the figure after discount and before PPN, which
     * is what Penjualan carries in the ledger — so this total and the profit
     * and loss are the same number, and if they ever are not, one of them is
     * a bug rather than a difference of opinion.
     *
     * @return array<string, array{label: string, nilai: int, jumlah: int}>
     */
    private function revenue(Period $period, SalesDimension $dimension): array
    {
        $query = DB::table('invoices')
            ->whereBoundRegion('invoices')
            ->join('orders', 'invoices.order_id', '=', 'orders.id')
            ->join('order_lines', 'orders.id', '=', 'order_lines.order_id')
            ->join('companies', 'invoices.company_id', '=', 'companies.id')
            ->leftJoin('products', 'order_lines.sku', '=', 'products.kode')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->whereBetween('invoices.issued_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNotNull('order_lines.line_total_rupiah');

        [$key, $label] = $this->grouping($dimension);

        return $query
            ->selectRaw("{$key} AS k, MIN({$label}) AS l, SUM(order_lines.line_total_rupiah) AS nilai, COUNT(DISTINCT invoices.id) AS jumlah")
            ->groupByRaw($key)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->k => [
                'label' => (string) ($row->l ?? $row->k),
                'nilai' => (int) $row->nilai,
                'jumlah' => (int) $row->jumlah,
            ]])
            ->all();
    }

    /**
     * Credit notes posted in the period, which reduce what was sold.
     *
     * Posted only. A draft is somebody's intention, and letting one lower a
     * revenue figure is how a return that was never agreed ends up in a
     * report the owner acts on.
     *
     * The status filter is **deliberately redundant** with the date filter
     * beside it: a draft has no `posted_at`, so it already falls outside any
     * period. Nothing in the schema binds those two columns together though —
     * they are set by the same line of the poster, which is a convention
     * rather than a constraint — so the check stays as a statement of what
     * this query means. It cannot be made to fail by any state the poster can
     * produce, which is why no test covers it.
     *
     * @return array<string, int>
     */
    private function credits(Period $period, SalesDimension $dimension): array
    {
        $query = DB::table('credit_notes')
            ->whereBoundRegion('credit_notes')
            ->join('credit_note_lines', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->join('companies', 'credit_notes.company_id', '=', 'companies.id')
            ->leftJoin('products', 'credit_note_lines.sku', '=', 'products.kode')
            ->where('credit_notes.status', CreditNote::STATUS_POSTED)
            ->whereBetween('credit_notes.posted_at', [$period->from, $period->to]);

        $key = match ($dimension) {
            SalesDimension::Pelanggan => 'companies.id',
            SalesDimension::Merk => 'products.merk',
            SalesDimension::Kategori => 'products.kategori',
            SalesDimension::Bulan => "to_char(credit_notes.posted_at, 'YYYY-MM')",
        };

        return $query
            ->selectRaw("{$key} AS k, SUM(credit_note_lines.line_total_rupiah) AS nilai")
            ->groupByRaw($key)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->k => (int) $row->nilai])
            ->all();
    }

    /**
     * Cost of what those invoices covered, frozen when the goods left.
     *
     * Joined through the *order*, not through the calendar: a shipment in
     * September against an August invoice belongs to August's margin, because
     * it is the cost of that sale. Retur movements come back the same way and
     * reduce it.
     *
     * @return array<string, int>
     */
    private function cost(Period $period, SalesDimension $dimension): array
    {
        $key = match ($dimension) {
            SalesDimension::Pelanggan => 'companies.id',
            SalesDimension::Merk => 'products.merk',
            SalesDimension::Kategori => 'products.kategori',
            SalesDimension::Bulan => "to_char(invoices.issued_on, 'YYYY-MM')",
        };

        return DB::table('invoices')
            ->whereBoundRegion('invoices')
            ->join('orders', 'invoices.order_id', '=', 'orders.id')
            ->join('companies', 'invoices.company_id', '=', 'companies.id')
            ->join('stock_movements', function ($join) {
                $join->on('stock_movements.reference_id', '=', DB::raw('orders.id::text'))
                    ->where('stock_movements.reference_type', '=', Order::class);
            })
            ->leftJoin('products', 'stock_movements.sku', '=', 'products.kode')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->whereBetween('invoices.issued_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereIn('stock_movements.reason', [
                MovementReason::Pengiriman->value,
                MovementReason::Retur->value,
            ])
            ->whereNotNull('stock_movements.value_rupiah')
            // Outbound values are negative, so negating gives cost of sales
            // and a return quietly nets itself off.
            ->selectRaw("{$key} AS k, -SUM(stock_movements.value_rupiah) AS nilai")
            ->groupByRaw($key)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->k => (int) $row->nilai])
            ->all();
    }

    /** @return array{0: string, 1: string} group expression, label expression */
    private function grouping(SalesDimension $dimension): array
    {
        return match ($dimension) {
            SalesDimension::Pelanggan => ['companies.id', 'companies.nama'],
            SalesDimension::Merk => ['products.merk', 'products.merk'],
            SalesDimension::Kategori => ['products.kategori', 'products.kategori'],
            SalesDimension::Bulan => [
                "to_char(invoices.issued_on, 'YYYY-MM')",
                "to_char(invoices.issued_on, 'YYYY-MM')",
            ],
        };
    }

    /** @return list<ReportColumn> */
    private function columns(SalesDimension $dimension, bool $withCost): array
    {
        $showCost = fn () => $withCost;

        return [
            ReportColumn::text('dimensi', $dimension->label()),
            ReportColumn::number('faktur', 'Faktur'),
            ReportColumn::money('penjualan', 'Penjualan'),
            ReportColumn::money('hpp', 'HPP', $showCost),
            ReportColumn::money('margin', 'Margin', $showCost),
            ReportColumn::percent('margin_persen', 'Margin %', $showCost),
        ];
    }

    private function totals(array $rows, bool $withCost): array
    {
        $penjualan = array_sum(array_column($rows, 'penjualan'));
        $hpp = array_sum(array_column($rows, 'hpp'));

        return [
            'faktur' => array_sum(array_column($rows, 'faktur')),
            'penjualan' => $penjualan,
            'hpp' => $withCost ? $hpp : null,
            'margin' => $withCost ? $penjualan - $hpp : null,
            'margin_persen' => $withCost && $penjualan !== 0
                ? round(($penjualan - $hpp) / $penjualan * 100, 2)
                : null,
        ];
    }

    /**
     * What a reader has to know before quoting these numbers.
     *
     * Both caveats are about the same thing: a figure that is right on its own
     * terms and misleading if you assume something it does not do.
     *
     * @return list<string>
     */
    private function caveats(Period $period, SalesDimension $dimension, bool $withCost): array
    {
        $catatan = [];

        if ($withCost) {
            $unshipped = $this->unshippedInvoiceCount($period);

            if ($unshipped > 0) {
                $catatan[] = sprintf(
                    '%d faktur pada periode ini barangnya belum dikirim, jadi HPP-nya belum ada. '
                    .'Margin untuk faktur tersebut terlihat lebih besar dari yang sebenarnya.',
                    $unshipped,
                );
            }
        }

        if (! $dimension->canPlaceUnlinkedCredits() && $this->hasUnlinkedCredits($period)) {
            $catatan[] = 'Ada nota kredit berupa potongan yang tidak terkait baris barang, '
                .'jadi tidak bisa dibagi per '.strtolower($dimension->label())
                .'. Lihat laporan per pelanggan untuk angka yang lengkap.';
        }

        return $catatan;
    }

    private function unshippedInvoiceCount(Period $period): int
    {
        return DB::table('invoices')
            ->whereBoundRegion('invoices')
            ->join('orders', 'invoices.order_id', '=', 'orders.id')
            ->where('invoices.status', '!=', Invoice::STATUS_VOID)
            ->whereBetween('invoices.issued_on', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNull('orders.shipped_at')
            ->count();
    }

    private function hasUnlinkedCredits(Period $period): bool
    {
        return DB::table('credit_notes')
            ->whereBoundRegion('credit_notes')
            ->join('credit_note_lines', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_notes.status', CreditNote::STATUS_POSTED)
            ->whereBetween('credit_notes.posted_at', [$period->from, $period->to])
            ->whereNull('credit_note_lines.order_line_id')
            ->exists();
    }
}
