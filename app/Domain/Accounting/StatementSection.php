<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * A named block of lines with a subtotal — "Aset", "Beban Operasional".
 */
final readonly class StatementSection
{
    /** @param  list<StatementLine>  $lines */
    public function __construct(
        public string $label,
        public array $lines,
    ) {}

    public function total(): int
    {
        return array_sum(array_map(fn (StatementLine $l) => $l->amount, $this->lines));
    }

    /** @return list<StatementLine> */
    public function nonZeroLines(): array
    {
        return array_values(array_filter($this->lines, fn (StatementLine $l) => $l->amount !== 0));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
