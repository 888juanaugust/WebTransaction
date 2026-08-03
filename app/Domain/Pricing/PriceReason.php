<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Why resolvePrice() returned the number it returned.
 *
 * This is stored on the order line alongside the price, so that "why is this
 * customer paying that?" is answerable from the row itself a year later.
 */
enum PriceReason: string
{
    case CompanyOverridePrice = 'company_override_harga';
    case CompanyOverrideDiscount = 'company_override_diskon';
    case TierItemPrice = 'tier_harga';
    case TierItemDiscount = 'tier_diskon';
    case TierBlanketDiscount = 'tier_diskon_umum';
    case ListPrice = 'harga_list';
    case NotPriced = 'tidak_ada_harga';

    public function label(): string
    {
        return match ($this) {
            self::CompanyOverridePrice => 'Harga khusus pelanggan',
            self::CompanyOverrideDiscount => 'Diskon khusus pelanggan',
            self::TierItemPrice => 'Harga tingkat pelanggan',
            self::TierItemDiscount => 'Diskon tingkat pelanggan (per SKU)',
            self::TierBlanketDiscount => 'Diskon tingkat pelanggan',
            self::ListPrice => 'Harga list',
            self::NotPriced => 'Tidak ada harga berlaku',
        };
    }

    public function isPriced(): bool
    {
        return $this !== self::NotPriced;
    }
}
