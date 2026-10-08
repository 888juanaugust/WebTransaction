<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Units;

use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Converts a quantity in one of an item's units to the base unit (unit 1)
 * and back. The stock ledger is always in base units; lines keep both.
 * Exact decimal arithmetic, four places, half-up.
 */
final class UnitConverter
{
    /** The ratio of a unit to the item's base unit: 1 for unit 1, 12 for a dozen. */
    public static function ratio(Item $item, Unit|int $unit): string
    {
        $unitId = $unit instanceof Unit ? $unit->id : $unit;
        if ($unitId === $item->unit1_id) {
            return '1';
        }

        $itemUnit = $item->units->firstWhere('unit_id', $unitId);
        if ($itemUnit === null) {
            throw new InvalidArgumentException("Unit {$unitId} is not a unit of item {$item->number}.");
        }

        return (string) $itemUnit->ratio;
    }

    /** 2 dozen → "24.0000". */
    public static function toBase(Item $item, string|int|float $quantity, Unit|int $unit): string
    {
        return (string) BigDecimal::of((string) $quantity)
            ->multipliedBy(self::ratio($item, $unit))
            ->toScale(4, RoundingMode::HalfUp);
    }

    /** 24 → "2.0000" dozen. */
    public static function fromBase(Item $item, string|int|float $baseQuantity, Unit|int $unit): string
    {
        return (string) BigDecimal::of((string) $baseQuantity)
            ->dividedBy(self::ratio($item, $unit), 4, RoundingMode::HalfUp);
    }
}
