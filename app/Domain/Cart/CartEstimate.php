<?php

declare(strict_types=1);

namespace App\Domain\Cart;

/**
 * The whole basket's indicative figures. Read-only, never persisted.
 */
class CartEstimate
{
    /**
     * @param  array<int, CartLineEstimate>  $lines  keyed by cart item id
     */
    public function __construct(
        public readonly array $lines = [],
        public readonly int $subtotal = 0,
        public readonly int $ppn = 0,
        public readonly int $total = 0,
        /** False when any line has no published price. */
        public readonly bool $fullyPriced = true,
        public readonly ?int $creditAvailable = null,
    ) {}

    public function line(int $cartItemId): CartLineEstimate
    {
        return $this->lines[$cartItemId] ?? new CartLineEstimate;
    }

    /**
     * Would this basket, at today's prices, exceed the credit still available?
     *
     * An indication only. The binding check happens at `confirmed`, against
     * exposure as it stands then.
     */
    public function exceedsCredit(): bool
    {
        return $this->creditAvailable !== null
            && $this->fullyPriced
            && $this->total > $this->creditAvailable;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return array_values(array_filter(
            array_map(fn (CartLineEstimate $line) => $line->problem, $this->lines)
        ));
    }
}
