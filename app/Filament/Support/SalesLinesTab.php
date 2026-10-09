<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Sales\Contracts\Prices;
use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;

/** The sales line grid: the priced grid with a salesperson per line and prices from the resolver; typing a price takes a right. */
final class SalesLinesTab
{
    public static function make(array $before = [], bool $prices = true, bool $warehouse = true, bool $processed = false): Tab
    {
        $tab = PricedDocumentForm::linesTab(
            before: $before,
            prices: $prices,
            warehouse: $warehouse,
            processed: $processed,
            priceResolver: function (Item $item, Get $get) {
                $customer = $get('../../customer_id') ? Customer::query()->find($get('../../customer_id')) : null;

                $unitId = $get('unit_id') ? (int) $get('unit_id') : null;
                $item->loadMissing('units');
                $baseQuantity = is_numeric($get('quantity')) ? UnitConverter::toBase($item, (string) $get('quantity'), $unitId ?? $item->unit1_id) : null;

                return app(Prices::class)->resolve($customer, $item, $unitId, $get('../../trans_date') ?: today(), $baseQuantity);
            },
            salesman: true,
            groupItems: true,
        );

        return $tab;
    }

    public static function canTypePrices(): bool
    {
        return app(HakAkses::class)->allowsSpecial(auth()->user(), HakKhusus::ChangeSellingPrice);
    }
}
