<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Regions\RegionContext;
use App\Models\ProductCost;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What the stock is worth, and what it cost to sell.
 *
 * Moving average, held as a (quantity, value) pair per SKU — see the
 * product_costs migration for why a pair rather than a unit cost, and why one
 * average for the company rather than one per warehouse.
 *
 * The rule that makes this an accounting record rather than a report: **cost is
 * decided once, at the moment of the movement, and written onto the movement.**
 * A shipment stamps the average that applied when it left. A receipt arriving
 * next week moves the average for everything after it and changes nothing
 * before it. That is what stops last month's gross margin from quietly changing
 * because somebody bought stock today.
 */
class InventoryValuation
{
    /**
     * Add received goods to the average.
     *
     * Value in, quantity in, and the pair stays exact — no division happens
     * here at all, which is the entire point of storing a pair.
     *
     * Must be called inside the caller's transaction, holding the same lock as
     * the movement it accompanies.
     */
    public function applyReceipt(string $sku, int $qtyBase, int $valueRupiah): ProductCost
    {
        if ($qtyBase <= 0) {
            throw new LogicException("A receipt of {$qtyBase} base units is not a receipt.");
        }

        $cost = $this->lock($sku);

        $cost->qty_base += $qtyBase;
        $cost->value_rupiah += $valueRupiah;

        // "What did we pay last time" — a question the average cannot answer,
        // and the one a buyer actually asks before reordering.
        $cost->last_cost_rupiah = intdiv($valueRupiah * 2 + $qtyBase, $qtyBase * 2);
        $cost->last_received_at = now();

        $cost->save();

        return $cost;
    }

    /**
     * Raise what the goods on hand are worth, without any arriving.
     *
     * Landed cost is the only thing that does this: the freight invoice turns
     * up a fortnight after the container, and it says those cartons cost more
     * than we booked them at. The quantity is unchanged; the value is not.
     *
     * **Only against stock we still have.** Adding value to a SKU at zero
     * quantity would leave value with nothing under it — inventory worth money
     * and holding nothing, and a unit cost of infinity for whatever arrives
     * next. The caller works out the share belonging to goods already sold and
     * sends that to cost of sales instead; by the time it reaches here, the
     * figure is the on-shelf share and there is something to put it on.
     *
     * Must be called inside the caller's transaction.
     */
    public function addCost(string $sku, int $valueRupiah): ProductCost
    {
        if ($valueRupiah <= 0) {
            throw new LogicException("An added cost of {$valueRupiah} is not a cost.");
        }

        $cost = $this->lock($sku);

        if ($cost->qty_base <= 0) {
            throw new LogicException(
                "Cannot add cost to {$sku}: nothing on hand to carry it."
            );
        }

        $cost->value_rupiah += $valueRupiah;
        $cost->save();

        return $cost;
    }

    /**
     * Take goods out at the current average, and report what they were worth.
     *
     * Returns the value removed as a positive number; the caller writes it onto
     * the movement with the sign that movement carries. For a sale this figure
     * is the cost of goods sold, frozen at this instant.
     *
     * Must be called inside the caller's transaction.
     */
    public function applyIssue(string $sku, int $qtyBase): IssuedCost
    {
        if ($qtyBase <= 0) {
            throw new LogicException("An issue of {$qtyBase} base units is not an issue.");
        }

        $cost = $this->lock($sku);

        /*
         * Nothing on hand, or nothing valued.
         *
         * This happens for stock that entered the system before goods receipts
         * existed, or through a seeder. Reporting zero cost is a lie that shows
         * up as impossible margin, so the movement is left unvalued instead and
         * unvaluedIssues() can find it. Better a gap somebody can see than a
         * number nobody can trust.
         */
        if ($cost->qty_base <= 0 || $cost->value_rupiah <= 0) {
            return new IssuedCost(unitCost: null, value: null, valued: false);
        }

        $unitCost = $cost->unitCost();

        /*
         * Take value out in proportion to quantity, not by multiplying the
         * rounded unit cost — and when the last unit leaves, take the whole
         * remaining balance with it. Otherwise a SKU that has gone in and out a
         * few hundred times ends at zero quantity holding a few stray rupiah of
         * value, and inventory value never comes back to zero.
         */
        if ($qtyBase >= $cost->qty_base) {
            $value = $cost->value_rupiah;
            $issued = $cost->qty_base;

            $cost->qty_base = 0;
            $cost->value_rupiah = 0;
        } else {
            $value = intdiv($cost->value_rupiah * $qtyBase * 2 + $cost->qty_base, $cost->qty_base * 2);
            $issued = $qtyBase;

            $cost->qty_base -= $qtyBase;
            $cost->value_rupiah -= $value;
        }

        $cost->save();

        return new IssuedCost(
            unitCost: $unitCost,
            value: $value,
            valued: true,
            // Below zero on hand the average has nothing to say. The movement
            // is still valued at the last known average, but the caller may
            // want to know the books and the shelf disagree.
            shortfall: max(0, $qtyBase - $issued),
        );
    }

    /** The current average cost of one base unit, or zero if none is known. */
    public function unitCost(string $sku): int
    {
        return ProductCost::query()->where('sku', $sku)->first()?->unitCost() ?? 0;
    }

    /** Total value of everything on hand, in whole rupiah. */
    public function totalValue(): int
    {
        return (int) ProductCost::query()->sum('value_rupiah');
    }

    /**
     * Cost of goods sold over a period: the value that left through shipments.
     *
     * Returned positive. Movements written before cost existed are excluded and
     * counted separately by unvaluedIssues() — a COGS figure quietly missing
     * half its rows is worse than one that says so.
     */
    public function costOfGoodsSold(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): int
    {
        $query = StockMovement::query()
            ->where('reason', MovementReason::Pengiriman->value)
            ->whereNotNull('value_rupiah');

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return -(int) $query->sum('value_rupiah');
    }

    /** Movements that left stock without a cost attached. */
    public function unvaluedIssues(): int
    {
        return StockMovement::query()
            ->where('qty_signed', '<', 0)
            ->whereNull('value_rupiah')
            ->count();
    }

    /**
     * Base units sitting in the ledger that the valuation has never accounted
     * for — stock that arrived before costing existed, or through a seeder.
     *
     * This is the number that says "inventory value is understated, and by how
     * many units". The cure is an opening-balance receipt: count the shelf and
     * enter what is on it as a goods receipt at its known cost.
     *
     * @return array<string, int> sku => unvalued base units
     */
    public function unvaluedQuantity(): array
    {
        return StockMovement::query()
            ->selectRaw('sku, SUM(qty_signed) AS qty')
            ->whereNull('value_rupiah')
            ->groupBy('sku')
            ->having(DB::raw('SUM(qty_signed)'), '!=', 0)
            ->pluck('qty', 'sku')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * Rebuild the cached pair by replaying the ledger.
     *
     * The invariant is that this changes nothing. Because each movement carries
     * the value that applied when it happened, the replay is a plain sum — it
     * does not re-derive averages, so it cannot disagree with the movements it
     * is checking.
     *
     * Only *valued* movements are replayed. A movement with a null value is one
     * the valuation never saw — stock from before costing existed — and
     * counting it here would report a permanent, unfixable discrepancy on every
     * run. A check that always cries wolf is a check people learn to ignore, so
     * that gap is reported by unvaluedQuantity() instead, where it reads as the
     * work item it actually is.
     *
     * @return list<array{sku: string, cached_qty: int, ledger_qty: int, cached_value: int, ledger_value: int}>
     */
    public function reconcile(): array
    {
        $ledger = StockMovement::query()
            ->selectRaw('sku, SUM(qty_signed) AS qty, COALESCE(SUM(value_rupiah), 0) AS value')
            ->whereNotNull('value_rupiah')
            ->groupBy('sku')
            ->get()
            ->keyBy('sku');

        $drift = [];

        foreach (ProductCost::query()->orderBy('sku')->get() as $cost) {
            $row = $ledger->get($cost->sku);

            $ledgerQty = (int) ($row->qty ?? 0);
            $ledgerValue = (int) ($row->value ?? 0);

            if ($ledgerQty !== $cost->qty_base || $ledgerValue !== $cost->value_rupiah) {
                $drift[] = [
                    'sku' => $cost->sku,
                    'cached_qty' => $cost->qty_base,
                    'ledger_qty' => $ledgerQty,
                    'cached_value' => $cost->value_rupiah,
                    'ledger_value' => $ledgerValue,
                ];
            }
        }

        return $drift;
    }

    /**
     * SELECT ... FOR UPDATE the cost row, creating it on first touch.
     *
     * Same shape as StockLedger::lockLevel — two concurrent first-touches race,
     * the unique index decides, and the loser re-reads under the lock.
     */
    private function lock(string $sku): ProductCost
    {
        $cost = ProductCost::query()->where('sku', $sku)->lockForUpdate()->first();

        if ($cost !== null) {
            return $cost;
        }

        /*
         * The cost pool is per region — each region's books average their own
         * purchases, so the same SKU legitimately carries a different average
         * in Jakarta and Surabaya. insertOrIgnore skips Eloquent events, so
         * the HasRegion stamp does not run and the region is explicit. It is
         * required rather than defaulted: valuation with no region bound is a
         * job that forgot to pin itself, and a cost pool filed under the wrong
         * region misprices every shipment out of it.
         */
        ProductCost::query()->insertOrIgnore([
            'region_id' => app(RegionContext::class)->requireRegionId(),
            'sku' => $sku,
            'qty_base' => 0,
            'value_rupiah' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ProductCost::query()->where('sku', $sku)->lockForUpdate()->firstOrFail();
    }
}
