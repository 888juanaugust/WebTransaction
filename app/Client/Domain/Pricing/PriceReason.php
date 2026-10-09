<?php

declare(strict_types=1);

namespace App\Client\Domain\Pricing;

/** Why a line got its price; stamped on the order line at approval. */
enum PriceReason: string
{
    case CustomerPrice = 'customer_price';
    case CustomerDiscount = 'customer_discount';
    case TierPrice = 'tier_price';
    case TierItemPrice = 'tier_item_price';
    case TierDiscount = 'tier_discount';
    case ListPrice = 'list_price';
    case BasePrice = 'base_price';
    case Unpriced = 'unpriced';

    public function label(): string
    {
        return match ($this) {
            self::CustomerPrice => __('Customer price'),
            self::CustomerDiscount => __('Customer discount'),
            self::TierPrice => __('Tier price adjustment'),
            self::TierItemPrice => __('Tier item price'),
            self::TierDiscount => __('Tier discount'),
            self::ListPrice => __('List price'),
            self::BasePrice => __('Item price'),
            self::Unpriced => __('No price in force'),
        };
    }

    public function isPriced(): bool
    {
        return $this !== self::Unpriced;
    }
}
