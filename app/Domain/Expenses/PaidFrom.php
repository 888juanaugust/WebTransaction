<?php

declare(strict_types=1);

namespace App\Domain\Expenses;

use App\Domain\Accounting\AccountCode;

/**
 * Which pocket the money left.
 *
 * Two, and only two. Everything else that reduces cash — paying a supplier
 * bill, a giro clearing — already has its own document and its own posting
 * rule, and offering those here would be a second way to do something the
 * system already does properly once.
 *
 * `Kas` is the reason this enum exists rather than the expense always crediting
 * Bank. The chart has had a Kas account since the beginning and nothing has
 * ever posted to it, so petty cash spending had nowhere to go and would have
 * been booked against the bank — which then fails to reconcile against a
 * statement that never mentioned it.
 */
enum PaidFrom: string
{
    case Kas = 'kas';
    case Bank = 'bank';

    public function accountCode(): string
    {
        return match ($this) {
            self::Kas => AccountCode::KAS,
            self::Bank => AccountCode::BANK,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Kas => 'Kas (tunai)',
            self::Bank => 'Bank (transfer)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
