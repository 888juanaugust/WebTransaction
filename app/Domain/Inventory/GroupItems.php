<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Shared\Enums\ItemType;
use App\Models\Inventory\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a quantity of an item moves in the stock ledger. A stocked item moves
 * itself; a group moves its stocked components (quantity per group unit times
 * the quantity sold, in each component's base unit); services and
 * non-inventory items move nothing. The same component listed twice is one
 * movement.
 */
final class GroupItems
{
    private const MAX_DEPTH = 4;

    /** @return list<array{item: Item, base_quantity: string}> */
    public static function explode(Item $item, string|int|float $baseQuantity): array
    {
        $pieces = [];
        self::walk($item, BigDecimal::of((string) $baseQuantity), 0, $pieces);

        return array_values(array_map(fn (array $piece) => [
            'item' => $piece['item'],
            'base_quantity' => (string) $piece['quantity']->toScale(4, RoundingMode::HalfUp),
        ], $pieces));
    }

    /** @param  array<int, array{item: Item, quantity: BigDecimal}>  $pieces */
    private static function walk(Item $item, BigDecimal $quantity, int $depth, array &$pieces): void
    {
        if ($item->item_type->isStocked()) {
            $pieces[$item->id] ??= ['item' => $item, 'quantity' => BigDecimal::zero()];
            $pieces[$item->id]['quantity'] = $pieces[$item->id]['quantity']->plus($quantity);

            return;
        }
        if ($item->item_type !== ItemType::Group || $depth >= self::MAX_DEPTH) {
            return;
        }
        foreach ($item->components()->with(['item.units', 'item.category'])->get() as $component) {
            $perGroup = UnitConverter::toBase($component->item, (string) $component->quantity, $component->unit_id ?? $component->item->unit1_id);
            self::walk($component->item, $quantity->multipliedBy($perGroup), $depth + 1, $pieces);
        }
    }
}
