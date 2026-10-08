<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Approval\ApprovalEngine;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseOrderLine;
use App\Models\Purchasing\PurchaseRequisition;
use App\Models\Purchasing\PurchaseRequisitionLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * What to reorder. Per item (and warehouse, when one is chosen): stock on
 * hand; on order (what approved, open purchase orders still bring); requested
 * (what approved, open requisitions still ask for, every warehouse); the
 * minimum (the warehouse's own, else the item's overall minimum); and the
 * quantity to order to get back to the minimum. All in base units.
 */
final class Replenishment
{
    /** @return array<int, string> item id → base quantity still to come on open, approved purchase orders */
    public static function onOrder(?int $warehouseId = null): array
    {
        $orders = self::approvedOpen(PurchaseOrder::class);
        $out = [];
        foreach (PurchaseOrderLine::query()->whereIn('purchase_order_id', $orders)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->get(['item_id', 'base_quantity', 'processed_quantity']) as $line) {
            $left = BigDecimal::of((string) $line->base_quantity)->minus((string) $line->processed_quantity);
            if ($left->isPositive()) {
                $out[$line->item_id] = (string) BigDecimal::of($out[$line->item_id] ?? '0')->plus($left);
            }
        }

        return $out;
    }

    /** @return array<int, string> item id → base quantity still asked for on open, approved requisitions */
    public static function requested(): array
    {
        $requisitions = self::approvedOpen(PurchaseRequisition::class);
        $out = [];
        foreach (PurchaseRequisitionLine::query()->whereIn('purchase_requisition_id', $requisitions)->get(['item_id', 'base_quantity', 'processed_quantity']) as $line) {
            $left = BigDecimal::of((string) $line->base_quantity)->minus((string) $line->processed_quantity);
            if ($left->isPositive()) {
                $out[$line->item_id] = (string) BigDecimal::of($out[$line->item_id] ?? '0')->plus($left);
            }
        }

        return $out;
    }

    /**
     * Items at or below their minimum.
     *
     * @return Collection<int, array{item: Item, on_hand: string, on_order: string, requested: string, minimum: string, to_order: string}>
     */
    public static function belowMinimum(?int $warehouseId = null, ?int $vendorId = null, string $search = ''): Collection
    {
        $onHand = $warehouseId
            ? ItemCost::query()->where('warehouse_id', $warehouseId)->pluck('qty_on_hand', 'item_id')->map(fn ($v) => (string) $v)->all()
            : StockQuery::onHandMap();
        $onOrder = self::onOrder($warehouseId);
        $requested = self::requested();

        return Item::query()->active()
            ->with(['unit1', 'preferredVendor', 'minimumStocks'])
            ->where('item_type', 'inventory')
            ->where(fn ($q) => $q->where('min_stock', '>', 0)->orWhereHas('minimumStocks', fn ($m) => $m->where('quantity', '>', 0)))
            ->when($vendorId, fn ($q, $v) => $q->where('preferred_vendor_id', $v))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%")))
            ->orderBy('number')
            ->get()
            ->map(function (Item $item) use ($warehouseId, $onHand, $onOrder, $requested): ?array {
                $minimum = self::minimumOf($item, $warehouseId);
                $stock = BigDecimal::of($onHand[$item->id] ?? '0');
                if (! $minimum->isPositive() || $stock->isGreaterThan($minimum)) {
                    return null;
                }
                $coming = BigDecimal::of($onOrder[$item->id] ?? '0')->plus($requested[$item->id] ?? '0');
                $toOrder = $minimum->minus($stock)->minus($coming);

                return [
                    'item' => $item,
                    'on_hand' => (string) $stock,
                    'on_order' => $onOrder[$item->id] ?? '0',
                    'requested' => $requested[$item->id] ?? '0',
                    'minimum' => (string) $minimum,
                    'to_order' => (string) ($toOrder->isPositive() ? $toOrder : BigDecimal::zero()),
                ];
            })
            ->filter()
            ->values();
    }

    /** The warehouse's own minimum when it has one; else the item's overall minimum (with no warehouse chosen, the larger of that and the warehouses' sum). */
    public static function minimumOf(Item $item, ?int $warehouseId): BigDecimal
    {
        $own = $item->minimumStocks;
        if ($warehouseId !== null) {
            $line = $own->firstWhere('warehouse_id', $warehouseId);

            return BigDecimal::of((string) ($line?->quantity ?? $item->min_stock));
        }
        $sum = $own->reduce(fn (BigDecimal $carry, $line) => $carry->plus((string) $line->quantity), BigDecimal::zero());
        $overall = BigDecimal::of((string) $item->min_stock);

        return $overall->isGreaterThan($sum) ? $overall : $sum;
    }

    /** @return list<int> ids of open (pending or partial) documents of the class that are approved */
    private static function approvedOpen(string $class): array
    {
        $engine = app(ApprovalEngine::class);

        return $class::query()->whereIn('status', ['pending', 'partial'])->get()
            ->filter(fn ($doc) => $engine->isApproved($doc))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
