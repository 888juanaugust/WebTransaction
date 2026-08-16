<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The five account types, in the Indonesian names the reports use.
 *
 * Everything downstream hangs off this: which statement an account appears on,
 * which side increases it, and whether its balance survives the year end.
 */
enum AccountType: string
{
    case Aset = 'aset';
    case Kewajiban = 'kewajiban';
    case Modal = 'modal';
    case Pendapatan = 'pendapatan';
    case Beban = 'beban';

    public function label(): string
    {
        return match ($this) {
            self::Aset => 'Aset',
            self::Kewajiban => 'Kewajiban',
            self::Modal => 'Modal',
            self::Pendapatan => 'Pendapatan',
            self::Beban => 'Beban',
        };
    }

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Aset, self::Beban => NormalBalance::Debit,
            self::Kewajiban, self::Modal, self::Pendapatan => NormalBalance::Kredit,
        };
    }

    /**
     * Neraca accounts carry their balance forward forever; laba rugi accounts
     * start each year at zero. That single distinction is the whole of what a
     * period close does.
     */
    public function isNeraca(): bool
    {
        return in_array($this, [self::Aset, self::Kewajiban, self::Modal], true);
    }

    public function isLabaRugi(): bool
    {
        return ! $this->isNeraca();
    }

    /** Report order: assets, liabilities, equity, income, expense. */
    public function sortOrder(): int
    {
        return match ($this) {
            self::Aset => 1,
            self::Kewajiban => 2,
            self::Modal => 3,
            self::Pendapatan => 4,
            self::Beban => 5,
        };
    }
}
