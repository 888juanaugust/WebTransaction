<?php

declare(strict_types=1);

namespace App\Domain\Assets;

/**
 * What a monthly run did.
 *
 * `dilewati` is not padding. A run that posts nothing because the month was
 * already done looks identical, from the outside, to a run that posts nothing
 * because no asset owes anything — and somebody pressing the button needs to
 * be told which.
 */
final readonly class DepreciationRun
{
    public function __construct(
        public string $periode,
        public int $diposting,
        public int $dilewati,
        public int $totalRupiah,
    ) {}

    public function didNothing(): bool
    {
        return $this->diposting === 0;
    }
}
