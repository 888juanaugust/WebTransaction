<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\Account;

/**
 * One line on a financial statement.
 *
 * `account` is null for a computed line — the current year's result on the
 * neraca has no account behind it until the books are closed, and pretending
 * otherwise would mean posting to Laba Ditahan every time somebody looked at
 * a report.
 */
final readonly class StatementLine
{
    public function __construct(
        public string $label,
        public int $amount,
        public ?Account $account = null,
    ) {}

    public function isComputed(): bool
    {
        return $this->account === null;
    }

    public function kode(): ?string
    {
        return $this->account?->kode;
    }
}
