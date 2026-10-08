<?php

declare(strict_types=1);

namespace App\Domain\Posting\Blockers;

use App\Domain\Posting\Contracts\Blocker;
use App\Domain\Posting\Contracts\Postable;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

/** A document another document has pulled lines from cannot go while those pulls exist. */
final class ReferencedBlocker implements Blocker
{
    public function blocks(Postable|Model $document): ?string
    {
        if (! method_exists($document, 'lines')) {
            return null;
        }
        foreach ($document->lines()->get() as $line) {
            if (isset($line->processed_quantity) && BigDecimal::of((string) $line->processed_quantity)->isPositive()) {
                return 'another document has been made from it; delete that one first.';
            }
        }

        return null;
    }
}
