<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Money;
use App\Models\OrderLine;

/**
 * One line of an invoice, and how much of it is still creditable.
 *
 * Built by CreditNoteIssuer and read by both the form and the posting rules,
 * so what a person is offered and what the system will accept are the same
 * calculation. Offering a quantity that posting then refuses is how a control
 * teaches people to distrust it.
 */
final readonly class CreditableLine
{
    public function __construct(
        public OrderLine $orderLine,
        public string $sku,
        public ?string $deskripsi,
        /** Base units that actually left the warehouse. */
        public int $shippedQty,
        /** Base units already credited on posted notes. */
        public int $creditedQty,
        /** Rupiah already credited against this line. */
        public int $creditedValue,
        /** Cost already put back on the shelf by earlier notes on this line. */
        public int $creditedCost,
        /** Frozen cost per base unit, from the shipment that sent them out. */
        public int $unitCostRupiah,
        /** Total cost of everything that shipped on this line. */
        public int $shippedCostRupiah,
    ) {}

    /** Base units that may still be returned. */
    public function remainingQty(): int
    {
        return max(0, $this->shippedQty - $this->creditedQty);
    }

    public function isFullyCredited(): bool
    {
        return $this->remainingQty() === 0;
    }

    /** The line's own value, net of discount, as invoiced. */
    public function lineTotalRupiah(): int
    {
        return (int) $this->orderLine->line_total_rupiah;
    }

    public function orderedQtyBase(): int
    {
        return (int) $this->orderLine->qty_base;
    }

    public function remainingValueRupiah(): int
    {
        return max(0, $this->lineTotalRupiah() - $this->creditedValue);
    }

    /**
     * What crediting `$qtyBase` of this line is worth.
     *
     * Apportioned from the invoiced line total rather than recomputed as
     * quantity times price. The line total is already net of whatever discount
     * was given, and multiplying a list price by a quantity would refund a
     * number the customer never paid.
     *
     * Against the *snapshotted* unit price the two agree exactly today, and
     * mutation testing says so: an order line's total is its unit price times
     * its quantity, both whole rupiah, so the total always divides evenly.
     * Apportioning is kept because that is an accident of how orders are
     * priced now, not a rule — CLAUDE.md permits unit prices at DECIMAL(18,4)
     * "rounded to whole rupiah at the line level", and the first fractional
     * one makes quantity times price drift from what was invoiced while this
     * still adds back up to it.
     *
     * **From what is left, not from the original.** See `costFor` below for
     * why; the same reasoning applies here the day a line total stops dividing
     * evenly, and doing it now costs nothing.
     */
    public function valueFor(int $qtyBase): int
    {
        $sisaQty = max(0, $this->orderedQtyBase() - $this->creditedQty);

        if ($sisaQty <= 0) {
            return 0;
        }

        return Money::mulDiv($this->remainingValueRupiah(), $qtyBase, $sisaQty);
    }

    /**
     * What `$qtyBase` cost us, at the price it left at.
     *
     * **Apportioned from what is still out, not from the whole shipment.**
     * That distinction is the whole method. Moving-average cost almost never
     * divides evenly by quantity, so dividing the original figure afresh on
     * every note rounds the same fraction up again and again: seven units that
     * left at Rp 80.000 came back, one note at a time, at Rp 80.003.
     *
     * Rp 3 of inventory value conjured out of rounding, cost of sales short by
     * the same, and — this is why it mattered more than the size suggests —
     * **nothing could see it**. The stock movement is recorded with the very
     * figure the journal posts, so the ledger and the costing subledger agreed
     * with each other perfectly; the control-account check compares those two
     * and had no third opinion to compare them against. It would have
     * accumulated quietly, one return at a time, for as long as the business
     * ran.
     *
     * Taking the remainder instead makes the last note settle the difference
     * by construction, which is the same rule `Money::allocate` applies to a
     * split known all at once. Returns arrive over time, so the remainder has
     * to be carried rather than computed in one pass — hence `creditedCost`.
     */
    public function costFor(int $qtyBase): int
    {
        $sisaQty = $this->remainingQty();

        if ($sisaQty <= 0) {
            return 0;
        }

        return Money::mulDiv($this->remainingCostRupiah(), $qtyBase, $sisaQty);
    }

    /** Shipment cost not yet put back by an earlier note. */
    public function remainingCostRupiah(): int
    {
        return max(0, $this->shippedCostRupiah - $this->creditedCost);
    }

    /** Unit price as invoiced, for the printed document. */
    public function unitPriceRupiah(): int
    {
        return $this->orderedQtyBase() > 0
            ? Money::mulDiv($this->lineTotalRupiah(), 1, $this->orderedQtyBase())
            : 0;
    }
}
