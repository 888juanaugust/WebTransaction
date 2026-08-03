<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Money;

/**
 * A customer's credit position at a moment in time, and whether a particular
 * order fits inside it.
 */
final readonly class CreditStatus
{
    /**
     * @param  int  $limit  Approved credit limit, rupiah.
     * @param  int  $outstanding  Unpaid invoiced amount, rupiah.
     * @param  int  $committed  Confirmed-but-not-yet-invoiced orders, rupiah.
     * @param  int  $orderAmount  The order being checked, rupiah. Zero when just reporting.
     * @param  list<string>  $blockers  Reasons the order cannot proceed on credit.
     */
    public function __construct(
        public int $limit,
        public int $outstanding,
        public int $committed,
        public int $orderAmount,
        public array $blockers,
    ) {}

    /** What the customer could still spend before this order. */
    public function available(): int
    {
        return $this->limit - $this->outstanding - $this->committed;
    }

    /** What would be left after it. */
    public function availableAfter(): int
    {
        return $this->available() - $this->orderAmount;
    }

    public function passes(): bool
    {
        return $this->blockers === [];
    }

    public function summary(): string
    {
        return sprintf(
            'Limit %s · terpakai %s · tersedia %s',
            Money::format($this->limit),
            Money::format($this->outstanding + $this->committed),
            Money::format($this->available()),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'outstanding' => $this->outstanding,
            'committed' => $this->committed,
            'order_amount' => $this->orderAmount,
            'available' => $this->available(),
            'available_after' => $this->availableAfter(),
            'passes' => $this->passes(),
            'blockers' => $this->blockers,
        ];
    }
}
