<?php

declare(strict_types=1);

namespace App\Domain\Cart;

/**
 * One basket line's indicative figures. Read-only, never persisted.
 */
class CartLineEstimate
{
    public function __construct(
        public readonly ?int $qtyBase = null,
        public readonly ?int $unitPrice = null,
        public readonly ?int $lineTotal = null,
        public readonly ?int $available = null,
        /** Something the buyer should fix or know about before submitting. */
        public readonly ?string $problem = null,
    ) {}

    public function isPriced(): bool
    {
        return $this->lineTotal !== null;
    }

    public function hasProblem(): bool
    {
        return $this->problem !== null;
    }
}
