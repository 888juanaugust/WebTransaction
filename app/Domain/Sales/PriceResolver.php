<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Inventory\Units\UnitConverter;
use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use App\Models\Sales\SellingPriceAdjustment;
use App\Models\Sales\SellingPriceAdjustmentLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The selling price of an item for a customer on a date, and why: the price
 * adjustment in force for the customer's price category, else the item's
 * price for that category, else the item's base price scaled to the unit.
 * Discount adjustments come from the customer's discount category (a price
 * category), else their price category. For an item that uses wholesale
 * prices, an adjustment line may start from a quantity: the highest break the
 * document's quantity reaches applies. Every sales document asks here;
 * nothing else computes a price.
 */
final class PriceResolver
{
    /**
     * @param  string|null  $baseQuantity  the line's quantity in base units, for wholesale breaks
     * @return array{price: string, discount_percent: string, source: string}
     */
    public static function resolve(?Customer $customer, Item $item, ?int $unitId, DateTimeInterface|string|null $date = null, ?string $baseQuantity = null): array
    {
        $date = Carbon::parse($date ?? today())->toDateString();
        $unitId ??= $item->unit1_id;
        $categoryId = $customer?->price_category_id;
        $discountCategoryId = $customer?->discount_price_category_id ?? $categoryId;
        $item->loadMissing(['units', 'prices']);
        // A line's own discount is the item's; the customer's default discount is on the total (the header), not here too.
        $discount = (string) ($item->default_discount ?? 0);
        $quantity = $item->use_wholesale_price && $baseQuantity !== null ? $baseQuantity : '0';

        if ($discountCategoryId !== null) {
            $discountAdjusted = self::adjustment($discountCategoryId, $item, $unitId, $date, SellingPriceAdjustment::DISCOUNT, $quantity);
            if ($discountAdjusted !== null) {
                $discount = $discountAdjusted['value'];
            }
        }

        if ($categoryId !== null) {
            $adjusted = self::adjustment($categoryId, $item, $unitId, $date, SellingPriceAdjustment::PRICE, $quantity);
            if ($adjusted !== null) {
                return ['price' => $adjusted['value'], 'discount_percent' => $discount, 'source' => "price adjustment {$adjusted['number']}"];
            }

            $categoryPrice = $item->prices->first(fn ($p) => $p->price_category_id === $categoryId && $p->unit_id === $unitId)
                ?? $item->prices->first(fn ($p) => $p->price_category_id === $categoryId && $p->unit_id === null);
            if ($categoryPrice !== null) {
                $price = $categoryPrice->unit_id === null && $unitId !== $item->unit1_id
                    ? self::scale((string) $categoryPrice->price, $item, $unitId)
                    : (string) $categoryPrice->price;

                return ['price' => $price, 'discount_percent' => $discount, 'source' => 'item price for the price category'];
            }
        }

        $unitPrice = $item->units->first(fn ($u) => $u->unit_id === $unitId);
        if ($unitPrice !== null && (int) $unitPrice->sell_price > 0) {
            return ['price' => (string) $unitPrice->sell_price, 'discount_percent' => $discount, 'source' => 'item unit price'];
        }

        return ['price' => self::scale((string) $item->sell_price, $item, $unitId), 'discount_percent' => $discount, 'source' => 'item base price'];
    }

    /**
     * The adjustment line in force: the latest adjustment wins, a line for the
     * unit before a line for any unit; among wholesale breaks the quantity
     * reaches, the highest.
     *
     * @return array{value: string, number: string}|null
     */
    private static function adjustment(int $categoryId, Item $item, int $unitId, string $date, string $type, string $baseQuantity): ?array
    {
        $lines = SellingPriceAdjustmentLine::query()
            ->join('selling_price_adjustments as a', 'a.id', '=', 'selling_price_adjustment_lines.selling_price_adjustment_id')
            ->where('a.price_category_id', $categoryId)
            ->where('a.sales_adjustment_type', $type)
            ->where('a.is_active', true)
            ->where('a.trans_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('a.end_date')->orWhere('a.end_date', '>=', $date))
            ->where('selling_price_adjustment_lines.item_id', $item->id)
            ->where(fn ($q) => $q->where('selling_price_adjustment_lines.unit_id', $unitId)->orWhereNull('selling_price_adjustment_lines.unit_id'))
            ->orderByDesc('a.trans_date')
            ->orderByRaw('selling_price_adjustment_lines.unit_id IS NULL')
            ->select('selling_price_adjustment_lines.*', 'a.number as adjustment_number')
            ->get();

        // Each line's break in base units; a line without one always applies.
        $breakOf = fn ($line): BigDecimal => BigDecimal::of((string) $line->min_quantity)->isZero()
            ? BigDecimal::zero()
            : BigDecimal::of(UnitConverter::toBase($item, (string) $line->min_quantity, $line->unit_id ?? $item->unit1_id));
        $reached = $lines->filter(fn ($line) => BigDecimal::of($baseQuantity)->isGreaterThanOrEqualTo($breakOf($line)));
        $breaks = $reached->filter(fn ($line) => $breakOf($line)->isPositive());
        $line = $breaks->isNotEmpty()
            ? $breaks->sort(fn ($a, $b) => $breakOf($b)->compareTo($breakOf($a)))->first()
            : $reached->first();

        return $line ? ['value' => (string) $line->value, 'number' => $line->adjustment_number] : null;
    }

    private static function scale(string $basePrice, Item $item, int $unitId): string
    {
        return (string) BigDecimal::of($basePrice)->multipliedBy(UnitConverter::ratio($item, $unitId))->toScale(4, RoundingMode::HalfUp);
    }
}
