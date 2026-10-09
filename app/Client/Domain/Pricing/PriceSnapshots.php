<?php

declare(strict_types=1);

namespace App\Client\Domain\Pricing;

use App\Domain\Sales\Contracts\Prices;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;
use RuntimeException;

/**
 * Why every line of an order got its price, written when the order is
 * approved: the reason and the price list version in force on the order's
 * date. The price itself was fixed when the line was saved (the selling
 * price guard saw to it); the stamp records the why, so history reads
 * without the live list.
 */
final class PriceSnapshots
{
    public function __construct(private readonly Prices $prices) {}

    /** Refuses an order with a line nothing prices. */
    public function assertPriced(SalesOrder $order): void
    {
        foreach ($this->resolveLines($order) as [$line, $answer]) {
            if (($answer['reason'] ?? null) === PriceReason::Unpriced->value) {
                throw new RuntimeException(__(':item has no price in force on :date; publish a price list or set a customer price first.', ['item' => $line->item->name, 'date' => $order->trans_date->toDateString()]));
            }
        }
    }

    public function stamp(SalesOrder $order): void
    {
        foreach ($this->resolveLines($order) as [$line, $answer]) {
            $line->forceFill(['price_reason' => $answer['reason'] ?? PriceReason::BasePrice->value, 'price_list_version_id' => $answer['version_id'] ?? null])->saveQuietly();
        }
    }

    /** @return list<array{0: SalesOrderLine, 1: array}> */
    private function resolveLines(SalesOrder $order): array
    {
        $out = [];
        foreach ($order->lines()->with('item.units')->get() as $line) {
            if ($line->item === null) {
                continue;
            }
            $out[] = [$line, $this->prices->resolve($order->customer, $line->item, $line->unit_id ? (int) $line->unit_id : null, $order->trans_date, (string) $line->base_quantity)];
        }

        return $out;
    }
}
