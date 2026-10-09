<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contracts;

use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use DateTimeInterface;

/**
 * Where a selling price comes from. The base prices from price categories,
 * adjustments and the item (ListPrices); an installation may bind its own
 * source, which the line grid and the selling price guard then share.
 */
interface Prices
{
    /**
     * @param  string|null  $baseQuantity  the line's quantity in base units, for quantity breaks
     * @return array{price: string, discount_percent: string, source: string, reason?: string, version_id?: int|null}
     */
    public function resolve(?Customer $customer, Item $item, ?int $unitId, DateTimeInterface|string|null $date = null, ?string $baseQuantity = null): array;
}
