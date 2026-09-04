<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * One request, several deliveries.
 *
 * When approval finds an order's goods scattered across warehouses it splits
 * the order into one transaction per shipping warehouse, each booked in its
 * own region with that region's document number and linked back through
 * `orders.split_parent_id`. That is right for the books and invisible to
 * everybody reading a screen: the customer who placed one order now has two
 * numbers in their history, and the salesperson looking at either one sees no
 * sign that the other exists.
 *
 * This is the reader that puts them back together. It answers one question —
 * what else shipped against this request — and it is deliberately the only
 * place that knows the family is `split_parent_id ?? id`.
 *
 * **Region scope is lifted on purpose.** The pieces live in different regions'
 * books by design, so a scoped query would return exactly the piece you
 * already had and report "not split" about an order that was. That is the
 * sanctioned aggregation from CLAUDE.md — across regions when filtered to one
 * company — and the filter here is stronger than a company: it is one original
 * order. Nothing region-wide is totalled, and the caller still decides what a
 * given seat may read of a piece (money stays behind canSeePrices()).
 */
class OrderFamily
{
    /**
     * Every piece of this order's family, oldest first, including the order
     * passed in. A single-warehouse order is a family of one.
     *
     * @return Collection<int, Order>
     */
    public function pieces(Order $order): Collection
    {
        $rootId = $this->rootId($order);

        return Order::query()
            ->withoutGlobalScope('region')
            /*
             * The warehouses need the scope lifted too. Lifting it on the
             * orders alone returns the foreign piece with a null gudang —
             * the eager load would go looking for a Surabaya warehouse in
             * Jakarta's books — and the screen would say the one thing the
             * section exists to explain is unknown.
             */
            ->with(['warehouse' => fn ($q) => $q->withoutGlobalScope('region')->with('region')])
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('split_parent_id', $rootId))
            ->orderBy('id')
            ->get();
    }

    /**
     * The other pieces — what this screen is not already showing.
     *
     * @return Collection<int, Order>
     */
    public function siblings(Order $order): Collection
    {
        return $this->pieces($order)
            ->reject(fn (Order $piece) => (int) $piece->id === (int) $order->id)
            ->values();
    }

    /** Was this order split across warehouses? */
    public function isSplit(Order $order): bool
    {
        if ($order->split_parent_id !== null) {
            return true; // a piece knows without asking
        }

        return Order::query()
            ->withoutGlobalScope('region')
            ->where('split_parent_id', $order->id)
            ->exists();
    }

    /**
     * The order the split began from — the piece that kept the original
     * request, whether or not it was later re-homed to another region.
     */
    public function rootId(Order $order): int
    {
        return (int) ($order->split_parent_id ?? $order->id);
    }
}
