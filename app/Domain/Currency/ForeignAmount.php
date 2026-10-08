<?php

declare(strict_types=1);

namespace App\Domain\Currency;

/** What a journal line moves in a foreign currency, in its minor units, debit positive: kept on the lines of a foreign-currency bank account. */
final class ForeignAmount
{
    public function __construct(public readonly int $currencyId, public readonly int $amount) {}
}
