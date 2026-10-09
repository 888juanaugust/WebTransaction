<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Contracts\Prices;
use App\Models\Inventory\Item;
use App\Models\Sales\Customer;
use DateTimeInterface;

/** The base's own price source: the resolver, as it always was. */
final class ListPrices implements Prices
{
    public function resolve(?Customer $customer, Item $item, ?int $unitId, DateTimeInterface|string|null $date = null, ?string $baseQuantity = null): array
    {
        return PriceResolver::resolve($customer, $item, $unitId, $date, $baseQuantity);
    }
}
