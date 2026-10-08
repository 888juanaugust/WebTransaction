<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use Brick\Math\BigDecimal;

/**
 * The lines a purchase order or requisition starts with when Minimum Stock
 * opens it (?reorder=item:quantity,…&warehouse=ID): each item in its base
 * unit, at the quantity still to order.
 */
final class ReorderLines
{
    /** @return array{lines: list<array<string, mixed>>, vendor_id: int|null} */
    public static function fromRequest(bool $priced): array
    {
        $param = (string) request()->query('reorder', '');
        if ($param === '') {
            return ['lines' => [], 'vendor_id' => null];
        }
        $warehouseId = request()->integer('warehouse') ?: Warehouse::default()?->id;
        $wanted = [];
        foreach (explode(',', $param) as $pair) {
            [$id, $quantity] = array_pad(explode(':', $pair, 2), 2, '0');
            if (ctype_digit($id) && is_numeric($quantity) && BigDecimal::of($quantity)->isPositive()) {
                $wanted[(int) $id] = $quantity;
            }
        }
        $items = Item::query()->whereKey(array_keys($wanted))->where('item_type', 'inventory')->get()->keyBy('id');
        $lines = [];
        foreach ($wanted as $id => $quantity) {
            $item = $items[$id] ?? null;
            if ($item === null) {
                continue;
            }
            $line = ['item_id' => $item->id, 'quantity' => $quantity, 'unit_id' => $item->unit1_id, 'base_quantity' => $quantity];
            $lines[] = $priced
                ? $line + ['unit_price' => (string) $item->purchase_price, 'discount_percent' => 0, 'tax_code_id' => $item->tax1_id, 'warehouse_id' => $warehouseId]
                : $line + ['estimated_price' => (string) $item->purchase_price];
        }
        $vendors = $items->pluck('preferred_vendor_id')->unique();

        return ['lines' => $lines, 'vendor_id' => $vendors->count() === 1 ? $vendors->first() : null];
    }
}
