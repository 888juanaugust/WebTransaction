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
     */
    public function valueFor(int $qtyBase): int
    {
        if ($this->orderedQtyBase() <= 0) {
            return 0;
        }

        return Money::mulDiv($this->lineTotalRupiah(), $qtyBase, $this->orderedQtyBase());
    }

    /**
     * What `$qtyBase` cost us, at the price it left at.
     *
     * Apportioned the same way, from the frozen shipment value, so a return of
     * everything gives back exactly the cost that was taken out — no rounding
     * residue left behind in inventory.
     */
    public function costFor(int $qtyBase): int
    {
        if ($this->shippedQty <= 0) {
            return 0;
        }

        return Money::mulDiv($this->shippedCostRupiah, $qtyBase, $this->shippedQty);
    }

    /** Unit price as invoiced, for the printed document. */
    public function unitPriceRupiah(): int
    {
        return $this->orderedQtyBase() > 0
            ? Money::mulDiv($this->lineTotalRupiah(), 1, $this->orderedQtyBase())
            : 0;
    }
}
