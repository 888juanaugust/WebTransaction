<?php

declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * One part that is running out, and what to do about it.
 *
 * A value object rather than an array so that the two quantities on it cannot
 * be mixed up. `saranQtyBase` and `saranQtyCtn` describe the same order in
 * different units, and passing the wrong one to a purchase order line is an
 * order twelve times too big.
 */
final readonly class ReorderSuggestion
{
    public function __construct(
        public string $sku,
        public string $nama,
        public string $merk,
        public int $qtyPerCtn,
        public string $satuanDasar,

        /** On the shelf, including what is fenced for confirmed orders. */
        public int $onHand,

        /** Fenced for confirmed orders. Will leave; not available to sell. */
        public int $reserved,

        /** Ordered and not yet received, on purchase orders still open. */
        public int $onOrder,

        /** What we can actually count on: on hand, less reserved, plus on order. */
        public int $posisi,

        public int $titikPesanUlang,

        /** Whether that figure was typed by a person or derived from history. */
        public bool $titikManual,

        /** Base units sold per day, over the measurement window. */
        public float $lajuHarian,

        public int $leadTimeHari,

        /** Whether the lead time was measured from real receipts or assumed. */
        public bool $leadTimeTerukur,

        public int $saranQtyCtn,
        public int $saranQtyBase,

        /** Who we last bought this from, if anybody. */
        public ?int $supplierId,
        public ?string $supplierNama,
    ) {}

    /**
     * Days of cover left at the current rate, or null if it never sells.
     *
     * Null rather than a large number, for the same reason the stock ageing
     * report does it: "never sells" and "sells slowly" are different problems
     * and infinity is not something anybody can sort by.
     */
    public function sisaHari(): ?float
    {
        if ($this->lajuHarian <= 0.0) {
            return null;
        }

        return round(max(0, $this->onHand - $this->reserved) / $this->lajuHarian, 1);
    }

    /** Nothing left that is not already promised to somebody. */
    public function isHabis(): bool
    {
        return $this->onHand - $this->reserved <= 0;
    }

    /**
     * Below the line with nothing on the way.
     *
     * The distinction that decides whether this is urgent: a part under its
     * reorder point with a purchase order already covering it is being dealt
     * with, and putting it in front of somebody again produces a second order.
     */
    public function isUncovered(): bool
    {
        return $this->onOrder === 0;
    }
}
