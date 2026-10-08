<?php

declare(strict_types=1);

namespace App\Domain\Posting\Contracts;

/**
 * A document that changes other records when saved (an asset's location,
 * status or terms), undone when it is deleted. Run by the repository after
 * the posting, inside the same transaction.
 */
interface AppliesEffects
{
    public function applyEffects(): void;

    public function revertEffects(): void;
}
