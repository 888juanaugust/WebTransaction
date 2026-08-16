<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * Which side increases an account.
 *
 * Debit and credit are not "plus" and "minus". They are two columns, and which
 * one means "more" depends on the account: cash goes up on the debit side, a
 * payable goes up on the credit side, and both of those are increases. Getting
 * this backwards is how a report shows a negative liability.
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Kredit = 'kredit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit',
            self::Kredit => 'Kredit',
        };
    }

    /**
     * The balance of an account, given its two column totals.
     *
     * Positive means the account holds what it is supposed to hold. Negative is
     * not an error in itself — a bank account genuinely can be overdrawn — but
     * a negative inventory or a negative sales figure is worth a hard look.
     */
    public function balance(int $debit, int $kredit): int
    {
        return match ($this) {
            self::Debit => $debit - $kredit,
            self::Kredit => $kredit - $debit,
        };
    }
}
