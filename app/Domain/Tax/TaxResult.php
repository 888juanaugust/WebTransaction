<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/** The tax figures of one line, every amount in whole rupiah. */
final class TaxResult
{
    public function __construct(
        /** the price before tax */
        public readonly int $base,
        /** the tax base (DPP): the part of the price the rate applies to */
        public readonly int $dpp,
        public readonly int $tax,
        /** what the buyer pays: base + tax */
        public readonly int $gross,
    ) {}
}
