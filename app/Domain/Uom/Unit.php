<?php

declare(strict_types=1);

namespace App\Domain\Uom;

use App\Models\Product;
use InvalidArgumentException;

/**
 * Unit of measure is modeled, not assumed.
 *
 * A product has a base unit (PCS or SET) and a qty_per_ctn. Buyers order in
 * either the base unit or in cartons (dus/karton); the stock ledger only ever
 * counts base units.
 */
enum Unit: string
{
    case Pcs = 'PCS';
    case Set = 'SET';
    case Ctn = 'CTN';

    public function label(): string
    {
        return match ($this) {
            self::Pcs => 'PCS',
            self::Set => 'SET',
            self::Ctn => 'DUS',
        };
    }

    public function isBaseUnit(): bool
    {
        return $this !== self::Ctn;
    }

    /**
     * Convert an ordered quantity into base units.
     *
     * Ordering in the product's own base unit is 1:1. Ordering by the carton
     * multiplies by qty_per_ctn. Ordering in a base unit the product does not
     * use (asking for SET of a PCS product) is a bug, not a conversion.
     */
    public function toBaseQty(int $qty, Unit $baseUnit, int $qtyPerCtn): int
    {
        if ($baseUnit === self::Ctn) {
            throw new InvalidArgumentException('CTN cannot be a base unit.');
        }

        if ($this === self::Ctn) {
            if ($qtyPerCtn < 1) {
                throw new InvalidArgumentException('qty_per_ctn must be at least 1 to order by carton.');
            }

            return $qty * $qtyPerCtn;
        }

        if ($this !== $baseUnit) {
            throw new InvalidArgumentException(
                "Cannot order {$this->value} of a product whose base unit is {$baseUnit->value}."
            );
        }

        return $qty;
    }

    public function toBaseQtyForProduct(int $qty, Product $product): int
    {
        return $this->toBaseQty($qty, Unit::from($product->satuan_dasar), $product->qty_per_ctn);
    }
}
