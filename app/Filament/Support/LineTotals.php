<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Shared\Money;

/** Sums of repeater line state, for live totals under a line grid. */
final class LineTotals
{
    public static function sum(mixed $lines, string $column): int
    {
        $total = 0;
        foreach ((array) $lines as $line) {
            $total += Money::parse((string) ($line[$column] ?? 0));
        }

        return $total;
    }
}
