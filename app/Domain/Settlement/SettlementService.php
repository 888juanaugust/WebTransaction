<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Currency\Currencies;
use App\Domain\Documents\PaymentStatus;
use App\Models\Settlement\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a document has been paid: the sum of active allocations (amount plus
 * discount taken). paid_amount is a cache written here and only here; the
 * status derives from it. Nothing ever flips "paid" by hand. A document in a
 * foreign currency is paid when its own currency's amount is, and keeps
 * fc_paid_amount beside the base one.
 */
final class SettlementService
{
    public function paidAmount(Model $document, ?Model $except = null): int
    {
        return (int) $this->allocations($document, $except)->selectRaw('COALESCE(SUM(amount + discount), 0) AS paid')->value('paid');
    }

    /** In the document's own currency, for a foreign one. */
    public function foreignPaidAmount(Model $document, ?Model $except = null): int
    {
        return (int) $this->allocations($document, $except)->selectRaw('COALESCE(SUM(COALESCE(fc_amount, 0) + COALESCE(fc_discount, 0)), 0) AS paid')->value('paid');
    }

    public function refresh(Model $document): void
    {
        $paid = $this->paidAmount($document);
        $credit = method_exists($document, 'isCredit') && $document->isCredit();
        // A credit (a return) is applied with negative amounts; it is settled when the credit is used up.
        if ($credit) {
            $paid = -$paid;
        }
        $total = (int) ($document->getAttribute('total') ?? 0) - (int) ($document->getAttribute('down_payment_total') ?? 0);
        $values = ['paid_amount' => $paid, 'payment_status' => PaymentStatus::derive($total, $paid)];
        if ($this->isForeign($document)) {
            $fcPaid = $this->foreignPaidAmount($document) * ($credit ? -1 : 1);
            $values['fc_paid_amount'] = $fcPaid;
            $values['payment_status'] = PaymentStatus::derive($this->foreignTotal($document), $fcPaid);
        }
        $document->forceFill($values)->saveQuietly();
    }

    public function balance(Model $document): int
    {
        $paid = $this->paidAmount($document);
        if (method_exists($document, 'isCredit') && $document->isCredit()) {
            return -((int) $document->getAttribute('total') + $paid);
        }

        return (int) ($document->getAttribute('total') ?? 0) - (int) ($document->getAttribute('down_payment_total') ?? 0) - $paid;
    }

    /** What is still open in the document's own currency (negative for a credit). */
    public function foreignBalance(Model $document, ?Model $except = null): int
    {
        $paid = $this->foreignPaidAmount($document, $except);
        if (method_exists($document, 'isCredit') && $document->isCredit()) {
            return -($this->foreignTotal($document) + $paid);
        }

        return $this->foreignTotal($document) - $paid;
    }

    /** The open base balance, leaving out what one payment allocated (the payment being edited). */
    public function balanceExcept(Model $document, ?Model $except): int
    {
        $paid = $this->paidAmount($document, $except);
        if (method_exists($document, 'isCredit') && $document->isCredit()) {
            return -((int) $document->getAttribute('total') + $paid);
        }

        return (int) ($document->getAttribute('total') ?? 0) - (int) ($document->getAttribute('down_payment_total') ?? 0) - $paid;
    }

    /**
     * What was open on a date: settlements dated after it are not yet counted (an aging as at the end of a past
     * month shows what was owed then, not what is owed now). Base currency, or the document's own when $foreign.
     */
    public function balanceAsOf(Model $document, string $date, bool $foreign = false): int
    {
        $paid = (int) $this->allocations($document, null)->where('trans_date', '<=', $date)
            ->selectRaw($foreign ? 'COALESCE(SUM(COALESCE(fc_amount, 0) + COALESCE(fc_discount, 0)), 0) AS paid' : 'COALESCE(SUM(amount + discount), 0) AS paid')
            ->value('paid');
        $total = $foreign ? $this->foreignTotal($document)
            : (int) ($document->getAttribute('total') ?? $document->getAttribute('amount') ?? 0) - (int) ($document->getAttribute('down_payment_total') ?? 0);
        if (method_exists($document, 'isCredit') && $document->isCredit()) {
            return -($total + $paid);
        }

        return $total - $paid;
    }

    public function isForeign(Model $document): bool
    {
        return Currencies::isForeign($document->getAttribute('currency_id')) && $document->getAttribute($this->foreignTotalColumn($document)) !== null;
    }

    /** The document's total in its own currency, less down payments deducted. */
    public function foreignTotal(Model $document): int
    {
        return (int) $document->getAttribute($this->foreignTotalColumn($document)) - (int) ($document->getAttribute('fc_down_payment_total') ?? 0);
    }

    public function hasAllocations(Model $document): bool
    {
        return PaymentAllocation::query()->active()
            ->where('receivable_type', $document->getMorphClass())
            ->where('receivable_id', $document->getKey())
            ->exists();
    }

    private function foreignTotalColumn(Model $document): string
    {
        return array_key_exists('fc_total', $document->getAttributes()) ? 'fc_total' : 'fc_amount';
    }

    private function allocations(Model $document, ?Model $except): Builder
    {
        return PaymentAllocation::query()->active()
            ->where('receivable_type', $document->getMorphClass())
            ->where('receivable_id', $document->getKey())
            ->when($except, fn ($q) => $q->where(fn ($q) => $q->where('payment_type', '!=', $except->getMorphClass())->orWhere('payment_id', '!=', $except->getKey())));
    }
}
