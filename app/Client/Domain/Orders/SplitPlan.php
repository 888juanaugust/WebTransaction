<?php

declare(strict_types=1);

namespace App\Client\Domain\Orders;

use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesOrderLine;

/**
 * How an order's goods would be served: one share per shipping warehouse, in
 * priority order (the home warehouse first), each a list of lines and base
 * quantities; and what no warehouse can cover.
 */
final class SplitPlan
{
    /**
     * @param  list<array{warehouse: Warehouse, lines: list<array{line: SalesOrderLine, quantity: string}>}>  $shares
     * @param  list<array{line: SalesOrderLine, quantity: string}>  $shortfalls
     */
    public function __construct(public readonly array $shares, public readonly array $shortfalls) {}

    /** More than one warehouse ships. */
    public function needsSplit(): bool
    {
        return count($this->shares) > 1;
    }

    public function coversAll(): bool
    {
        return $this->shortfalls === [];
    }

    /** @return list<Warehouse> */
    public function warehouses(): array
    {
        return array_map(fn (array $share) => $share['warehouse'], $this->shares);
    }
}
