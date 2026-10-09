<?php

declare(strict_types=1);

namespace App\Client\Domain\Pricing;

use App\Client\Models\CustomerPriceRule;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Sales\Contracts\Prices;
use App\Domain\Sales\PriceResolver;
use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use App\Models\Sales\PriceCategory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Central's price source, on the base's seam. Customer rules first, always;
 * then the tier's own rules for the item (the base's adjustments and item
 * prices for the price category); then the tier's blanket discount; then the
 * list price of the version in force; last, the base's item price. Every
 * answer names its reason and, from the list, its version.
 */
final class CentralPrices implements Prices
{
    /** @return array{price: string, discount_percent: string, source: string, reason: string, version_id: int|null} */
    public function resolve(?Customer $customer, Item $item, ?int $unitId, DateTimeInterface|string|null $date = null, ?string $baseQuantity = null): array
    {
        $date = Carbon::parse($date ?? today())->toDateString();
        $unitId ??= (int) $item->unit1_id;
        $item->loadMissing(['units', 'prices']);
        $quantity = BigDecimal::of($baseQuantity ?? '1');
        $base = PriceResolver::resolve($customer, $item, $unitId, $date, $baseQuantity);
        $list = $this->listPrice($item, $date);

        // 1. The customer's own rules: the item's first, then blanket; the highest reached quantity break wins.
        if ($customer !== null) {
            $rule = $this->customerRule($customer, $item, $quantity, $date);
            if ($rule !== null) {
                if ($rule->price !== null) {
                    return $this->answer($this->scale((string) $rule->price, $item, $unitId), '0', PriceReason::CustomerPrice, "customer rule {$rule->id}", $list['version_id'] ?? null);
                }
                if ($list !== null) {
                    return $this->answer($this->scale((string) $list['price'], $item, $unitId), (string) $rule->discount_percent, PriceReason::CustomerDiscount, "customer rule {$rule->id}", $list['version_id']);
                }

                // No list price to discount: the discount applies to whatever the base prices the item at.
                return $this->answer($base['price'], (string) $rule->discount_percent, PriceReason::CustomerDiscount, "customer rule {$rule->id}", null);
            }
        }

        // 2. The tier's rules for the item: a price adjustment or an item price for the price category.
        if (str_starts_with($base['source'], 'price adjustment')) {
            return $this->answer($base['price'], $base['discount_percent'], PriceReason::TierPrice, $base['source'], null);
        }
        if ($base['source'] === 'item price for the price category') {
            return $this->answer($base['price'], $base['discount_percent'], PriceReason::TierItemPrice, $base['source'], null);
        }

        // 3. The tier's blanket discount, on the list price, unless the item carries a larger one.
        $blanket = $this->blanketDiscount($customer);
        if ($list !== null) {
            $discount = BigDecimal::max(BigDecimal::of($blanket), BigDecimal::of($base['discount_percent']));
            $reason = BigDecimal::of($blanket)->isPositive() ? PriceReason::TierDiscount : PriceReason::ListPrice;

            // 4. The list price of the version in force.
            return $this->answer($this->scale((string) $list['price'], $item, $unitId), (string) $discount->toScale(4, RoundingMode::HalfUp), $reason, "price list version {$list['version_id']}", $list['version_id']);
        }

        // Last: the base's own price for the item; nothing at all is unpriced.
        $priced = BigDecimal::of($base['price'])->isPositive();

        return $this->answer($base['price'], $base['discount_percent'], $priced ? PriceReason::BasePrice : PriceReason::Unpriced, $base['source'], null);
    }

    /** @return array{price: int, version_id: int}|null the item's list price per base unit in the version in force */
    public function listPrice(Item $item, string $date): ?array
    {
        $version = PriceListVersion::effectiveOn($date);
        if ($version === null) {
            return null;
        }
        $row = PriceListItem::query()->where('version_id', $version->id)->where('item_id', $item->id)->first();
        if ($row === null || ! $row->is_active) {
            return null;
        }

        return ['price' => (int) $row->price, 'version_id' => (int) $version->id];
    }

    private function customerRule(Customer $customer, Item $item, BigDecimal $quantity, string $date): ?CustomerPriceRule
    {
        $rules = CustomerPriceRule::inForce($customer->id, $item->id, $date)
            ->filter(fn (CustomerPriceRule $r) => BigDecimal::of((string) $r->min_base_quantity)->isLessThanOrEqualTo($quantity))
            ->sortBy(fn (CustomerPriceRule $r) => [$r->item_id === null ? 1 : 0, -(float) $r->min_base_quantity, -$r->id])
            ->values();

        return $rules->first();
    }

    private function blanketDiscount(?Customer $customer): string
    {
        if ($customer?->price_category_id === null) {
            return '0';
        }

        return (string) (PriceCategory::query()->whereKey($customer->price_category_id)->value('blanket_discount_percent') ?? '0');
    }

    /** A price per base unit in the line's unit. */
    private function scale(string $pricePerBase, Item $item, int $unitId): string
    {
        return (string) BigDecimal::of($pricePerBase)->multipliedBy(UnitConverter::ratio($item, $unitId))->toScale(4, RoundingMode::HalfUp);
    }

    /** @return array{price: string, discount_percent: string, source: string, reason: string, version_id: int|null} */
    private function answer(string $price, string $discount, PriceReason $reason, string $source, ?int $versionId): array
    {
        return [
            'price' => (string) BigDecimal::of($price)->toScale(4, RoundingMode::HalfUp),
            'discount_percent' => (string) BigDecimal::of($discount)->toScale(4, RoundingMode::HalfUp),
            'source' => $source,
            'reason' => $reason->value,
            'version_id' => $versionId,
        ];
    }
}
