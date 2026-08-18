<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * Which way a statement line moved the bank balance.
 *
 * Named from **the bank account's** point of view rather than the
 * counterparty's, because that is the direction the statement is printed in
 * and the person doing this has the statement in front of them. A bank charge
 * is `keluar`; interest is `masuk`.
 */
enum StatementDirection: string
{
    case Masuk = 'masuk';

    case Keluar = 'keluar';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Uang masuk',
            self::Keluar => 'Uang keluar',
        };
    }

    /** +1 raises the bank balance, −1 lowers it. */
    public function sign(): int
    {
        return $this === self::Masuk ? 1 : -1;
    }

    /** Money in debits Bank; money out credits it. */
    public function debitsBank(): bool
    {
        return $this === self::Masuk;
    }
}
