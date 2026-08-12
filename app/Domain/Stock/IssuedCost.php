<?php

declare(strict_types=1);

namespace App\Domain\Stock;

/**
 * What it cost to take goods out of stock, decided at the moment they left.
 *
 * `valued` is false when there was no cost to apply — stock that entered before
 * goods receipts existed, or through a seeder. The movement is then written
 * with a null cost rather than a zero one, because zero cost reads as infinite
 * margin and nobody notices until a report is already in front of the owner.
 */
final readonly class IssuedCost
{
    public function __construct(
        /** Average cost of one base unit at the instant of issue. */
        public ?int $unitCost,
        /** Total value removed, positive. This is COGS for a sale. */
        public ?int $value,
        public bool $valued,
        /**
         * Base units issued beyond what the valuation believed was on hand.
         *
         * Non-zero means the books and the shelf disagree — usually stock that
         * arrived without a receipt. The movement still happens; the shortfall
         * is how you find out it needs a stock opname.
         */
        public int $shortfall = 0,
    ) {}
}
