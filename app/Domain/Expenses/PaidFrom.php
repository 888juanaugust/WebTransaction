<?php

declare(strict_types=1);

namespace App\Domain\Expenses;

use App\Domain\Accounting\AccountCode;
use App\Domain\Banking\BankAccounts;

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
            /*
             * The DEFAULT rekening, now that there can be several. Expenses,
             * deposits and asset purchases are low-volume flows and stay on
             * the main account by design — per-rekening choice lives where
             * the volume is, on customer and supplier payments. An expense
             * genuinely paid from another rekening is the default-account
             * discrepancy its reconciliation will surface, which is the
             * honest outcome until these flows earn their own selector.
             */
            self::Bank => app(BankAccounts::class)->default()->account->kode,
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
