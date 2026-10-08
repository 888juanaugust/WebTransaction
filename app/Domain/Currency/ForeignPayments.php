<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Domain\Documents\Accounts;
use App\Domain\Posting\PostingBuilder;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Receipts and payments in a foreign currency. Each line is typed in the
 * payment's currency (fc_amount, fc_discount); before posting, its base
 * amount and discount become the carrying value it takes off the document,
 * so the receivable or payable leaves the books exactly as it came in. The
 * bank moves at the payment's rate (money paid out of a foreign-currency
 * account at that account's own average rate), and the difference is the
 * realised exchange gain or loss.
 */
final class ForeignPayments
{
    public function __construct(private readonly FxSettlement $fx) {}

    /**
     * Sets each line's base amount and discount to its carrying value; returns the total at the payment's rate.
     *
     * @param  string  $relation  'receivable' or 'payable'
     */
    public function prepare(Model $payment, string $relation): int
    {
        $decimals = Currencies::decimals($payment->currency_id);
        $paid = 0;
        foreach ($payment->lines()->with($relation)->get() as $line) {
            $document = $line->{$relation};
            $this->assertSameCurrency($payment, $document);
            $settled = (int) $line->fc_amount + (int) $line->fc_discount;
            $carrying = $document !== null ? $this->fx->carrying($document, $settled, $payment) : 0;
            $discount = $settled !== 0 ? BigDecimal::of($carrying)->multipliedBy((int) $line->fc_discount)->dividedBy($settled, 0, RoundingMode::HalfUp)->toInt() : 0;
            $line->forceFill(['amount' => $carrying - $discount, 'discount' => $discount])->saveQuietly();
            $paid += Convert::toBase((int) $line->fc_amount, (string) $payment->exchange_rate, $decimals);
        }

        return $paid;
    }

    /** A base-currency receipt or payment never settles a foreign document, and a foreign one only its own currency's. */
    public function assertSameCurrency(Model $payment, ?Model $document): void
    {
        if ($document === null) {
            return;
        }
        $mine = Currencies::isForeign($payment->getAttribute('currency_id')) ? (int) $payment->getAttribute('currency_id') : null;
        $theirs = Currencies::isForeign($document->getAttribute('currency_id')) ? (int) $document->getAttribute('currency_id') : null;
        if ($mine !== $theirs) {
            throw new RuntimeException(__(':number is in :theirs; this payment is in :mine.', [
                'number' => (string) $document->getAttribute('number'),
                'theirs' => Currencies::code($theirs),
                'mine' => Currencies::code($mine),
            ]));
        }
    }

    /** The bank account must be in the payment's currency, or in the base currency. */
    public function assertBank(Model $payment, int $bankAccountId): ?int
    {
        $bankCurrency = Account::query()->whereKey($bankAccountId)->value('currency_id');
        if (! Currencies::isForeign($bankCurrency)) {
            return null;
        }
        if ((int) $bankCurrency !== (int) $payment->getAttribute('currency_id')) {
            throw new RuntimeException(__('The bank account is in :bank; the payment must be in :bank too.', ['bank' => Currencies::code($bankCurrency)]));
        }

        return (int) $bankCurrency;
    }

    /**
     * Posts the line allocations' exchange differences and returns the per-line base paid, for a receipt (money in) or a payment (money out).
     *
     * @return array{base: int, foreign: int, differences: array<int, int>}
     */
    public function amounts(Model $payment, bool $moneyIn, ?int $bankCurrency, int $bankAccountId, CarbonInterface $date): array
    {
        $decimals = Currencies::decimals($payment->currency_id);
        $foreign = (int) $payment->lines()->sum('fc_amount');
        $rate = (string) $payment->exchange_rate;
        if (! $moneyIn && $bankCurrency !== null) {
            $rate = $this->carryingRate($bankAccountId, $date) ?? $rate; // out of a foreign account at its own average rate
        }
        $differences = [];
        $base = 0;
        foreach ($payment->lines()->get() as $line) {
            $value = Convert::toBase((int) $line->fc_amount, $rate, $decimals);
            $differences[$line->id] = $moneyIn ? $value - (int) $line->amount : (int) $line->amount - $value;
            $base += $value;
        }

        return ['base' => $base, 'foreign' => $foreign, 'differences' => $differences];
    }

    public function postDifference(PostingBuilder $builder, int $difference, string $memo): void
    {
        if ($difference !== 0) {
            // A gain is a credit, a loss a debit.
            $builder->signed(Accounts::exchangeDifference($difference), -$difference, $memo);
        }
    }

    /** A foreign-currency account's base value per unit held: its base balance over its foreign balance, before the date's end. */
    private function carryingRate(int $accountId, CarbonInterface $date): ?string
    {
        $row = JournalLine::query()->active()->where('account_id', $accountId)->where('trans_date', '<=', $date->toDateString())
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS base, COALESCE(SUM(fc_amount), 0) AS foreign')->first();
        $foreign = (int) ($row->foreign ?? 0);
        if ($foreign <= 0) {
            return null;
        }
        $decimals = Currencies::decimals(Account::query()->whereKey($accountId)->value('currency_id'));

        return (string) BigDecimal::of((int) $row->base)->multipliedBy(BigDecimal::ten()->power($decimals))->dividedBy($foreign, 8, RoundingMode::HalfUp);
    }
}
