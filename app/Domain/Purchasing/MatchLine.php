<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

/**
 * One SKU, seen from all three documents at once.
 *
 * Quantities are base units. Values are what each document says the goods are
 * worth, excluding PPN — the PO's agreed price, the receipt's booked cost, and
 * what the supplier actually invoiced.
 */
final readonly class MatchLine
{
    public function __construct(
        public string $sku,
        public int $orderedQty,
        public int $receivedQty,
        public int $billedQty,
        public int $orderedValue,
        public int $receivedValue,
        public int $billedValue,
    ) {}

    /** Ordered but not yet delivered. Negative means over-delivered. */
    public function quantityGap(): int
    {
        return $this->orderedQty - $this->receivedQty;
    }

    /**
     * Delivered but not yet billed, in base units.
     *
     * Negative is the one to look at: the supplier has billed for more than
     * they delivered, which is usually a duplicated invoice line.
     */
    public function unbilledQty(): int
    {
        return $this->receivedQty - $this->billedQty;
    }

    /**
     * What the supplier charged against what we booked the goods in at.
     *
     * Positive means the bill is higher than the receipt — the price moved
     * after the goods were valued, and inventory is now understated.
     */
    public function priceVariance(): int
    {
        if ($this->billedQty <= 0) {
            return 0;
        }

        return $this->billedValue - $this->receivedValue;
    }

    /** Billed more than was delivered. Worth a phone call before paying. */
    public function isOverBilled(): bool
    {
        return $this->billedQty > $this->receivedQty;
    }

    public function isOverReceived(): bool
    {
        return $this->receivedQty > $this->orderedQty;
    }

    /**
     * Anything a person should look at.
     *
     * A partly-delivered order is not a variance — it is an order still in
     * progress, and flagging it would bury the real ones. What counts is being
     * billed for goods that did not arrive, receiving more than was ordered, or
     * a price that moved on goods already billed.
     */
    public function hasVariance(): bool
    {
        return $this->isOverBilled()
            || $this->isOverReceived()
            || $this->priceVariance() !== 0;
    }
}
