<?php

declare(strict_types=1);

namespace App\Client\Screens;

use App\Domain\Access\ScreenKey;
use App\Domain\Access\ScreenKind;
use App\Filament\Modul;

/** Central's own screens. A value is stored in access rights, so it never changes once in use. */
enum CentralScreen: string implements ScreenKey
{
    case Teams = 'client__teams';
    case OrderApprovals = 'client__order-approvals';
    case PriceList = 'client__price-list';
    case CustomerPrices = 'client__customer-prices';

    public function modul(): Modul
    {
        return match ($this) {
            self::Teams, self::OrderApprovals, self::CustomerPrices => Modul::Sales,
            self::PriceList => Modul::Inventory,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Teams => __('Customer Teams'),
            self::OrderApprovals => __('Order Approvals'),
            self::PriceList => __('Price List'),
            self::CustomerPrices => __('Customer Prices'),
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::OrderApprovals => 15,
            self::PriceList => 85,
            self::CustomerPrices => 505,
            self::Teams => 510,
        };
    }

    public function kind(): ScreenKind
    {
        return match ($this) {
            self::OrderApprovals, self::PriceList => ScreenKind::Work,
            self::Teams, self::CustomerPrices => ScreenKind::Setup,
        };
    }

    public function isReplicated(): bool
    {
        return true;
    }

    public function slug(): string
    {
        return str_replace('__', '/', $this->value);
    }
}
