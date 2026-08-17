<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Money;
use App\Domain\Stock\MovementReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What is on the shelf, what it is worth, and whether it is moving.
 *
 * The opposite question to a reorder point. That one asks what is running out;
 * this asks what is sitting there — capital converted into parts nobody buys,
 * which in a spare parts business is where the money quietly goes. Nothing
 * complains about dead stock. It does not run out, it does not fail an
 * availability check, it does not appear in any queue. It just occupies the
 * warehouse and the bank balance.
 *
 * **Cover, in months**, is the figure that decides something: quantity on hand
 * divided by the recent monthly sales rate. Six months of cover on a fast part
 * is prudent; six months on a part selling one a quarter is two years of stock.
 * Expressing both as one number is what makes them comparable.
 *
 * Parts that have never sold at all get no cover figure rather than a very
 * large one. Infinity is not a number somebody can sort by, and "never sold"
 * is a different problem from "sells slowly" — the first is a buying mistake,
 * the second is a stocking decision.
 */
class StockAgeing
{
    /**
     * How far back the sales rate is measured.
     *
     * A year, so that seasonal parts are not condemned on a quiet quarter.
     */
    private const RATE_WINDOW_DAYS = 365;

    public function build(?Carbon $asOf = null, int $minValue = 0): ReportTable
    {
        $asOf = ($asOf ?? Carbon::now())->copy()->endOfDay();
        $since = $asOf->copy()->subDays(self::RATE_WINDOW_DAYS);

        $sold = $this->soldSince($since, $asOf);
        $lastSale = $this->lastSaleDates($asOf);

        $rows = [];

        foreach ($this->onHand() as $sku => $stock) {
            if ($stock['nilai'] < $minValue) {
                continue;
            }

            $soldQty = (int) ($sold[$sku] ?? 0);
            $perMonth = $soldQty > 0 ? $soldQty / (self::RATE_WINDOW_DAYS / 30) : 0.0;

            $rows[] = [
                'dimensi' => (string) $sku,
                'nama' => $stock['nama'],
                'merk' => $stock['merk'],
                'qty' => $stock['qty'],
                'nilai' => $stock['nilai'],
                'terjual' => $soldQty,
                'terakhir_keluar' => $lastSale[$sku] ?? null,
                // Null rather than a huge number for something that has never
                // sold: "never" and "very slowly" are different problems.
                'cover_bulan' => $perMonth > 0 ? round($stock['qty'] / $perMonth, 1) : null,
                'diam' => isset($lastSale[$sku])
                    ? (int) Carbon::parse($lastSale[$sku])->diffInDays($asOf)
                    : null,
            ];
        }

        /*
         * Never-sold first, then slowest, then by how much money is tied up.
         * The ordering is the recommendation: the top of this list is where
         * the cash is stuck.
         */
        usort($rows, function (array $a, array $b) {
            if (($a['cover_bulan'] === null) !== ($b['cover_bulan'] === null)) {
                return $a['cover_bulan'] === null ? -1 : 1;
            }

            if ($a['cover_bulan'] === null) {
                return $b['nilai'] <=> $a['nilai'];
            }

            return $b['cover_bulan'] <=> $a['cover_bulan'];
        });

        $neverSold = array_filter($rows, fn (array $r) => $r['cover_bulan'] === null);

        return new ReportTable(
            judul: 'Perputaran stok',
            period: Period::between($since, $asOf),
            columns: [
                ReportColumn::text('dimensi', 'Kode'),
                ReportColumn::text('nama', 'Barang'),
                ReportColumn::text('merk', 'Merk'),
                ReportColumn::number('qty', 'Sisa stok'),
                ReportColumn::money('nilai', 'Nilai'),
                ReportColumn::number('terjual', 'Terjual setahun'),
                ReportColumn::date('terakhir_keluar', 'Terakhir keluar'),
                /*
                 * A decimal, not a percentage. Months of cover rendered
                 * through the percent formatter printed "24,3%" for what is
                 * two years of stock — not a rounding error but a different
                 * quantity, and one somebody would have acted on.
                 *
                 * No "days since last sale" column: it is the date beside it,
                 * subtracted, and carrying both made the table a column too
                 * wide for the screen.
                 */
                ReportColumn::decimal('cover_bulan', 'Cukup (bulan)'),
            ],
            rows: $rows,
            totals: [
                'dimensi' => 'TOTAL',
                'qty' => array_sum(array_column($rows, 'qty')),
                'nilai' => array_sum(array_column($rows, 'nilai')),
                'terjual' => array_sum(array_column($rows, 'terjual')),
            ],
            catatan: array_values(array_filter([
                $neverSold !== []
                    ? sprintf(
                        '%d barang belum pernah terjual sama sekali dalam setahun terakhir, '
                        .'senilai %s. Itu yang di atas daftar.',
                        count($neverSold),
                        Money::format((int) array_sum(array_column($neverSold, 'nilai'))),
                    )
                    : null,
                'Cukup untuk (bulan) = sisa stok dibagi rata-rata penjualan per bulan '
                .'selama setahun terakhir. Barang musiman bisa terlihat lambat di luar musimnya.',
            ])),
        );
    }

    /**
     * Quantity and value on hand, per SKU.
     *
     * Value comes from `product_costs` — the moving average pair the whole
     * costing layer maintains — apportioned by quantity. Not recomputed from
     * a unit cost, for the reason that layer exists: recomputing rounds every
     * time and the drift compounds.
     *
     * @return array<string, array{nama: string, merk: string, qty: int, nilai: int}>
     */
    private function onHand(): array
    {
        return DB::table('stock_levels')
            ->join('products', 'stock_levels.sku', '=', 'products.kode')
            ->leftJoin('product_costs', 'stock_levels.sku', '=', 'product_costs.sku')
            ->groupBy('stock_levels.sku', 'products.description', 'products.merk',
                'product_costs.qty_base', 'product_costs.value_rupiah')
            ->havingRaw('SUM(stock_levels.qty_on_hand) > 0')
            ->selectRaw(
                'stock_levels.sku AS sku, MIN(products.description) AS nama, MIN(products.merk) AS merk, '
                .'SUM(stock_levels.qty_on_hand) AS qty, '
                // Apportioned rather than multiplied by a rounded unit cost.
                .'CASE WHEN COALESCE(product_costs.qty_base, 0) > 0 '
                .'THEN ROUND(product_costs.value_rupiah::numeric * SUM(stock_levels.qty_on_hand) '
                .'/ product_costs.qty_base) ELSE 0 END AS nilai'
            )
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->sku => [
                'nama' => (string) ($row->nama ?? $row->sku),
                'merk' => (string) ($row->merk ?? ''),
                'qty' => (int) $row->qty,
                'nilai' => (int) $row->nilai,
            ]])
            ->all();
    }

    /** @return array<string, int> base units shipped in the window */
    private function soldSince(Carbon $since, Carbon $until): array
    {
        return DB::table('stock_movements')
            ->where('reason', MovementReason::Pengiriman->value)
            ->whereBetween('created_at', [$since, $until])
            ->groupBy('sku')
            ->selectRaw('sku, -SUM(qty_signed) AS qty')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->sku => (int) $row->qty])
            ->all();
    }

    /**
     * When each SKU last went out of the door.
     *
     * Shipments only. A transfer between our own warehouses is not a sale, and
     * counting it would make stock look alive because somebody moved it.
     *
     * @return array<string, string>
     */
    private function lastSaleDates(Carbon $until): array
    {
        return DB::table('stock_movements')
            ->where('reason', MovementReason::Pengiriman->value)
            ->where('created_at', '<=', $until)
            ->groupBy('sku')
            ->selectRaw('sku, MAX(created_at) AS terakhir')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->sku => Carbon::parse($row->terakhir)->toDateString(),
            ])
            ->all();
    }
}
