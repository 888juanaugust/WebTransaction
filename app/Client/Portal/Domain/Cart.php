<?php

declare(strict_types=1);

namespace App\Client\Portal\Domain;

use App\Client\Models\CustomerUser;
use App\Client\Models\PortalCart;
use App\Client\Models\PortalCartLine;
use App\Models\Inventory\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The buyer's cart: lines of active items in the item's own units, merged
 * on the same item and unit, owned by one login. Prices are never kept here;
 * the estimate prices the cart when shown and the placer when ordered.
 */
final class Cart
{
    public function forBuyer(CustomerUser $buyer): PortalCart
    {
        return PortalCart::query()->firstOrCreate(['customer_user_id' => $buyer->id], ['customer_id' => $buyer->customer_id]);
    }

    /** @return Collection<int, PortalCartLine> with item (and its units) and unit loaded */
    public function lines(PortalCart $cart): Collection
    {
        return $cart->lines()->with(['item.units', 'item.unit1', 'unit'])->get();
    }

    public function count(CustomerUser $buyer): int
    {
        return (int) PortalCartLine::query()->whereHas('cart', fn ($q) => $q->where('customer_user_id', $buyer->id))->count();
    }

    public function add(CustomerUser $buyer, Item $item, int $unitId, string|int|float $quantity): PortalCartLine
    {
        $active = array_key_exists('is_active', $item->getAttributes()) ? (bool) $item->is_active : (bool) Item::query()->whereKey($item->id)->value('is_active');
        if (! $active) {
            throw new RuntimeException(__(':item is not on sale.', ['item' => $item->name]));
        }
        self::assertUnit($item, $unitId);
        $qty = self::quantity($quantity);
        if ($qty->isLessThanOrEqualTo(0)) {
            throw new RuntimeException(__('The quantity must be above zero.'));
        }

        return DB::transaction(function () use ($buyer, $item, $unitId, $qty): PortalCartLine {
            $cart = $this->forBuyer($buyer);
            $line = PortalCartLine::query()->where('cart_id', $cart->id)->where('item_id', $item->id)->where('unit_id', $unitId)->lockForUpdate()->first();
            if ($line !== null) {
                $line->forceFill(['quantity' => BigDecimal::of((string) $line->quantity)->plus($qty)->toScale(4, RoundingMode::HalfUp)->__toString()])->save();

                return $line;
            }

            return $cart->lines()->create(['item_id' => $item->id, 'unit_id' => $unitId, 'quantity' => $qty->__toString()]);
        });
    }

    /** Zero removes the line. */
    public function setQuantity(CustomerUser $buyer, PortalCartLine $line, string|int|float $quantity): void
    {
        $this->assertOwned($buyer, $line);
        $qty = self::quantity($quantity);
        if ($qty->isLessThanOrEqualTo(0)) {
            $line->delete();

            return;
        }
        $line->forceFill(['quantity' => $qty->__toString()])->save();
    }

    public function remove(CustomerUser $buyer, PortalCartLine $line): void
    {
        $this->assertOwned($buyer, $line);
        $line->delete();
    }

    public function clear(CustomerUser $buyer): void
    {
        $cart = $this->forBuyer($buyer);
        $cart->lines()->delete();
        $cart->forceFill(['po_number' => null, 'note' => null])->save();
    }

    public static function assertUnit(Item $item, int $unitId): void
    {
        $item->loadMissing('units');
        if ($unitId !== (int) $item->unit1_id && $item->units->firstWhere('unit_id', $unitId) === null) {
            throw new RuntimeException(__(':item is not sold in that unit.', ['item' => $item->name]));
        }
    }

    /** @return array<int, string> unit id → unit name: the base unit and the item's other units */
    public static function unitsOf(Item $item): array
    {
        $item->loadMissing(['units.unit', 'unit1']);
        $out = [(int) $item->unit1_id => (string) $item->unit1?->name];
        foreach ($item->units as $itemUnit) {
            if ($itemUnit->unit !== null) {
                $out[(int) $itemUnit->unit_id] = (string) $itemUnit->unit->name;
            }
        }

        return $out;
    }

    private function assertOwned(CustomerUser $buyer, PortalCartLine $line): void
    {
        $line->loadMissing('cart');
        if ((int) $line->cart?->customer_user_id !== (int) $buyer->id) {
            throw new RuntimeException(__('That line is not in your cart.'));
        }
    }

    private static function quantity(string|int|float $quantity): BigDecimal
    {
        try {
            return BigDecimal::of((string) $quantity)->toScale(4, RoundingMode::HalfUp);
        } catch (\Throwable) {
            throw new RuntimeException(__('The quantity must be a number.'));
        }
    }
}
