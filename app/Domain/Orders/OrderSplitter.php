<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Stock\InsufficientStockException;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\Warehouse;

/**
 * Where each line of an order will ship from — home region first.
 *
 * Stock lives scattered across warehouses in several regions, and the rule
 * is loyalty before distance: drain the order's own warehouse, then the
 * rest of the customer's home region, and only then the other regions —
 * those by whoever has the most on the shelf, so a remainder ships from
 * one place instead of three.
 *
 * Pure planning: this class writes nothing, which is what lets the approval
 * modal show the split before the click, and the executor run the same plan
 * inside its transaction.
 */
class OrderSplitter
{
    /**
     * The allocation plan: warehouse id => list of line shares.
     *
     * The order's own warehouse comes first in the returned array when it
     * carries anything — the executor keeps that share on the original
     * order. Quantities may split mid-line; a line's shares always sum to
     * the line's full quantity.
     *
     * @return array<int, list<array{line_id: int, sku: string, qty_base: int}>>
     *
     * @throws InsufficientStockException when even every warehouse together
     *                                    cannot cover a line
     */
    public function plan(Order $order): array
    {
        $warehouses = $this->warehousesInPriorityOrder($order);
        $levels = $this->availableBySkuAndWarehouse($order);

        $plan = [];

        foreach ($order->lines()->orderBy('urutan')->orderBy('id')->get() as $line) {
            $sisa = (int) $line->qty_base;

            foreach ($warehouses as $warehouse) {
                if ($sisa <= 0) {
                    break;
                }

                $tersedia = $levels[$line->sku][$warehouse->id] ?? 0;

                if ($tersedia <= 0) {
                    continue;
                }

                $ambil = min($sisa, $tersedia);
                $levels[$line->sku][$warehouse->id] = $tersedia - $ambil;
                $sisa -= $ambil;

                $plan[$warehouse->id][] = [
                    'line_id' => (int) $line->id,
                    'sku' => $line->sku,
                    'qty_base' => $ambil,
                ];
            }

            if ($sisa > 0) {
                // Warehouse 0 as the marker for "all of them together".
                throw new InsufficientStockException(
                    sku: $line->sku,
                    warehouseId: 0,
                    requested: (int) $line->qty_base,
                    available: (int) $line->qty_base - $sisa,
                );
            }
        }

        return $plan;
    }

    /** True when the plan needs more than the order's own warehouse. */
    public function wouldSplit(Order $order): bool
    {
        $plan = $this->plan($order);

        return count($plan) > 1 || ! array_key_exists((int) $order->warehouse_id, $plan);
    }

    /**
     * Every active warehouse, in drain order: the order's own first, then
     * the rest of the customer's home region by kode, then other regions'
     * by how much they hold overall — most stock first, so a remainder
     * ships from one place.
     *
     * @return list<Warehouse>
     */
    private function warehousesInPriorityOrder(Order $order): array
    {
        $skus = $order->lines()->pluck('sku')->all();

        $holdings = StockLevel::query()
            ->withoutGlobalScope('region')
            ->whereIn('sku', $skus)
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(qty_on_hand - qty_reserved) AS bisa')
            ->pluck('bisa', 'warehouse_id');

        return Warehouse::query()
            ->withoutGlobalScope('region')
            ->where('aktif', true)
            ->get()
            ->sortBy(fn (Warehouse $w) => match (true) {
                (int) $w->id === (int) $order->warehouse_id => '0',
                (int) $w->region_id === (int) $order->region_id => '1-'.$w->kode,
                default => '2-'.str_pad((string) (10_000_000_000 - (int) ($holdings[$w->id] ?? 0)), 11, '0', STR_PAD_LEFT),
            })
            ->values()
            ->all();
    }

    /**
     * Available stock per SKU per warehouse, across every region, read once.
     *
     * @return array<string, array<int, int>>
     */
    private function availableBySkuAndWarehouse(Order $order): array
    {
        $levels = [];

        StockLevel::query()
            ->withoutGlobalScope('region')
            ->whereIn('sku', $order->lines()->pluck('sku')->all())
            ->get()
            ->each(function (StockLevel $level) use (&$levels) {
                $levels[$level->sku][(int) $level->warehouse_id] = max(0, (int) $level->qty_on_hand - (int) $level->qty_reserved);
            });

        return $levels;
    }
}
