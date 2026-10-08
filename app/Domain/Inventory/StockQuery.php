<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Models\Inventory\ItemCost;
use App\Models\Inventory\Warehouse;
use Illuminate\Support\Collection;

/** Reads of the stock cache: on hand per item, per warehouse, below minimum. */
final class StockQuery
{
    public static function onHand(int $itemId, ?int $warehouseId = null): string
    {
        $sum = ItemCost::query()->where('item_id', $itemId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereIn('warehouse_id', Warehouse::query()->where('is_system', false)->select('id'))
            ->sum('qty_on_hand');

        return (string) $sum;
    }

    /** @return Collection<int, ItemCost> one row per warehouse holding the item */
    public static function byWarehouse(int $itemId): Collection
    {
        return ItemCost::query()->with('warehouse')->where('item_id', $itemId)->get()->sortBy('warehouse.name')->values();
    }

    /** @return array<int, string> item id → on hand across real warehouses */
    public static function onHandMap(): array
    {
        return ItemCost::query()
            ->whereIn('warehouse_id', Warehouse::query()->where('is_system', false)->select('id'))
            ->selectRaw('item_id, SUM(qty_on_hand) AS qty')
            ->groupBy('item_id')
            ->pluck('qty', 'item_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }
}
