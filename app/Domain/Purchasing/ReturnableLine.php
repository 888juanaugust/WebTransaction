<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Money;
use App\Models\GoodsReceiptLine;

/**
 * One line of a goods receipt, and how much of it can still go back.
 *
 * Built by PurchaseReturnIssuer and read by both the form and the poster, so
 * the quantity somebody is offered is the quantity the system will accept.
 * Offering one and refusing it at the last moment is how a control teaches
 * people to work around it.
 *
 * Three quantities live here and they answer different questions:
 *
 *   - `receivedQty` — what arrived. The ceiling on everything.
 *   - `returnedQty` — what earlier posted returns have already sent back.
 *   - `billedQty`   — how much of it the supplier has invoiced.
 *
 * The last one is not a limit. It is the **split**: the billed part of a return
 * reduces a real debt, and the rest unwinds an accrual, and they go to
 * different accounts. See `splitFor()`.
 */
final readonly class ReturnableLine
{
    public function __construct(
        public GoodsReceiptLine $receiptLine,
        public string $sku,
        public ?string $deskripsi,
        /** Base units this receipt line brought in. */
        public int $receivedQty,
        /** What the receipt valued all of them at. */
        public int $receivedValueRupiah,
        /** Base units already sent back on posted returns. */
        public int $returnedQty,
        /** Base units of this line the supplier has billed, on posted bills. */
        public int $billedQty,
        /** What the supplier charged for that billed quantity. */
        public int $billedValueRupiah,
        /**
         * Which bills billed it.
         *
         * Normally one, and normally that is the whole story. More than one
         * means a single delivery line was invoiced across two bills, and the
         * return refuses rather than guessing which of them to credit — see
         * PurchaseReturnPoster.
         *
         * @var list<int>
         */
        public array $billIds,
        /** Base units already sent back and attributed to the billed portion. */
        public int $returnedBilledQty,
        /**
         * Whether the bill behind it carried a faktur pajak.
         *
         * Decides where the input VAT reversal lands: a credit to PPN Masukan
         * if it was creditable, and to Beban Operasional if it never was — the
         * exact mirror of how the bill booked it.
         */
        public bool $inputVatCreditable,
    ) {}

    /** Base units that may still be sent back. */
    public function remainingQty(): int
    {
        return max(0, $this->receivedQty - $this->returnedQty);
    }

    public function isFullyReturned(): bool
    {
        return $this->remainingQty() === 0;
    }

    /**
     * Billed quantity that has not yet been returned against.
     *
     * Capped at what arrived, because a supplier can bill for more than they
     * delivered — that is the whole reason the three-way match exists — and
     * without the cap this would offer to credit a debt against goods that
     * were never here. The poster never reaches the cap today, since it
     * refuses a quantity above `remainingQty()` first; it is here because this
     * is a public statement about a delivery, and one that can answer "120 of
     * the 100 you received" is wrong regardless of who is asking.
     */
    public function remainingBilledQty(): int
    {
        return max(0, min($this->billedQty, $this->receivedQty) - $this->returnedBilledQty);
    }

    public function isBilled(): bool
    {
        return $this->billedQty > 0;
    }

    /** The one bill this line credits, or null when there is not exactly one. */
    public function billId(): ?int
    {
        return count($this->billIds) === 1 ? $this->billIds[0] : null;
    }

    public function isSplitAcrossBills(): bool
    {
        return count($this->billIds) > 1;
    }

    /**
     * How returning `$qtyBase` divides between a real debt and an accrual.
     *
     * **Billed first**, and the order is a deliberate choice rather than an
     * arbitrary one. If a delivery is half billed and we attribute a return to
     * the unbilled half, Utang Belum Ditagih goes contra while the invoice for
     * goods we no longer have still stands in Utang Usaha — and the next
     * payment run pays it. Attributing to the debt first means the worst case
     * is a payable that goes briefly negative and is restored when the bill
     * arrives, which is untidy rather than expensive.
     *
     * @return array{0: int, 1: int} billed quantity, unbilled quantity
     */
    public function splitFor(int $qtyBase): array
    {
        $billed = min($qtyBase, $this->remainingBilledQty());

        return [$billed, $qtyBase - $billed];
    }

    /**
     * What `$qtyBase` went into stock at, on this receipt.
     *
     * Apportioned from the receipt's own line value rather than recomputed as
     * quantity times a unit cost. The supplier prices by the carton; dividing
     * that into a per-piece figure rounds once, and multiplying it back up
     * rounds again, and returning everything would then leave a few rupiah of
     * residue behind in inventory forever.
     */
    public function receiptCostFor(int $qtyBase): int
    {
        if ($this->receivedQty <= 0) {
            return 0;
        }

        return Money::mulDiv($this->receivedValueRupiah, $qtyBase, $this->receivedQty);
    }

    /**
     * What the supplier charged for `$qtyBase`, apportioned from what they
     * billed.
     *
     * Only meaningful for the billed portion. Where the supplier billed a
     * different price from the one the goods were received at, this is the
     * figure they will credit — not what stock is carried at.
     */
    public function billedValueFor(int $qtyBase): int
    {
        if ($this->billedQty <= 0) {
            return 0;
        }

        return Money::mulDiv($this->billedValueRupiah, $qtyBase, $this->billedQty);
    }

    /** Receipt cost of one base unit, for the screen. */
    public function unitCostRupiah(): int
    {
        return $this->receivedQty > 0
            ? Money::mulDiv($this->receivedValueRupiah, 1, $this->receivedQty)
            : 0;
    }
}
