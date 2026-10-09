<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Models\CustomerPriceRule;
use App\Client\Models\PriceListImport;
use App\Client\Models\PriceListItem;
use App\Client\Models\PriceListVersion;
use App\Client\Screens\CentralScreen;
use App\Client\Seeders\PriceListGroupSeeder;
use App\Modules\BaseModule;

/**
 * Central's price list: versioned list prices imported from the supplier's
 * workbook, the deals made with each customer, and the reason every order
 * line got its price. Always on.
 */
final class PriceListModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-price-list';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::PriceList, CentralScreen::CustomerPrices];
    }

    public static function morphMap(): array
    {
        return [
            'price_list_version' => PriceListVersion::class,
            'price_list_item' => PriceListItem::class,
            'price_list_import' => PriceListImport::class,
            'customer_price_rule' => CustomerPriceRule::class,
        ];
    }

    public static function defaultSeeders(): array
    {
        return [PriceListGroupSeeder::class];
    }
}
