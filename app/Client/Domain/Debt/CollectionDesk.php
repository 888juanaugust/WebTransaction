<?php

declare(strict_types=1);

namespace App\Client\Domain\Debt;

use App\Client\Access\CentralGroups;
use App\Client\Models\CollectionContact;
use App\Client\Screens\CentralScreen;
use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Settlement\SettlementService;
use App\Models\Sales\SalesInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The collection desk: who chased which unpaid invoice, how, and what the
 * customer promised. A promise moves no money and is never marked kept by
 * hand: it is kept when receipts dated after the contact settle what was
 * promised. The worklist buckets the chaseable invoices by what to do today.
 */
final class CollectionDesk
{
    public const DUE_TODAY = 'due_today';

    public const MISSED = 'missed';

    public const UNCONTACTED = 'uncontacted';

    public const REST = 'rest';

    public function __construct(
        private readonly HakAkses $access,
        private readonly ApprovalEngine $approvals,
        private readonly SettlementService $settlement,
    ) {}

    /** @param  array{method: string, outcome: string, promise_date?: ?string, promise_amount?: int|string|null, note?: ?string, contacted_at?: ?string}  $data */
    public function record(SalesInvoice $invoice, User $actor, array $data): CollectionContact
    {
        if ($invoice->payment_status === 'paid') {
            throw new RuntimeException(__(':number is paid; there is nothing to chase.', ['number' => $invoice->number]));
        }
        if (! $this->access->allows($actor, CentralScreen::Collections, Hak::Update) || ! $this->access->allowsSpecial($actor, HakKhusus::SeeCreditData)) {
            throw new RuntimeException(__('Recording a contact needs the Update right on Collections and the right to see credit data.'));
        }
        if (! $this->mayChase($actor, $invoice)) {
            throw new RuntimeException(__(':name is not one of your customers.', ['name' => $invoice->customer?->name]));
        }
        $method = (string) ($data['method'] ?? '');
        $outcome = (string) ($data['outcome'] ?? '');
        if (! in_array($method, CollectionContact::METHODS, true) || ! in_array($outcome, CollectionContact::OUTCOMES, true)) {
            throw new RuntimeException(__('Say how the customer was contacted and what came of it.'));
        }
        $promiseDate = null;
        $promiseAmount = null;
        if ($outcome === CollectionContact::PROMISE) {
            $promiseDate = filled($data['promise_date'] ?? null) ? CarbonImmutable::parse((string) $data['promise_date']) : null;
            if ($promiseDate === null) {
                throw new RuntimeException(__('A promise needs the day the customer will pay.'));
            }
            if ($promiseDate->isBefore(CarbonImmutable::today())) {
                throw new RuntimeException(__('A promise cannot be for a day that has passed.'));
            }
            $promiseAmount = filled($data['promise_amount'] ?? null) ? (int) $data['promise_amount'] : null;
            if ($promiseAmount !== null && $promiseAmount <= 0) {
                throw new RuntimeException(__('A promised amount must be above zero.'));
            }
        }

        return CollectionContact::query()->create([
            'sales_invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'user_id' => $actor->id, 'branch_id' => $invoice->branch_id,
            'method' => $method, 'outcome' => $outcome,
            'promise_date' => $promiseDate?->toDateString(), 'promise_amount' => $promiseAmount,
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
            'contacted_at' => filled($data['contacted_at'] ?? null) ? CarbonImmutable::parse((string) $data['contacted_at']) : now(),
        ]);
    }

    /** The invoices this user chases: unpaid, approved, in their branches; their own customers' for a Sales or Marketing seat. */
    public function chaseable(User $user): Builder
    {
        $query = BranchLimit::apply(SalesInvoice::query(), $user)->with(['customer', 'branch'])->where('payment_status', '!=', 'paid');
        if (! $user->isAdministrator() && (CentralGroups::isMember($user, CentralGroups::SALES) || CentralGroups::isMember($user, CentralGroups::MARKETING))) {
            $query->whereHas('customer', fn (Builder $c) => $c->where('sales_user_id', $user->id)->orWhere('marketing_user_id', $user->id));
        }

        return $query;
    }

    public function mayChase(User $user, SalesInvoice $invoice): bool
    {
        return $this->chaseable($user)->whereKey($invoice->id)->exists() && $this->approvals->isApproved($invoice);
    }

    /** The latest promise on the invoice, if any. */
    public function promise(SalesInvoice $invoice): ?CollectionContact
    {
        return CollectionContact::query()->where('sales_invoice_id', $invoice->id)->where('outcome', CollectionContact::PROMISE)->latest('contacted_at')->latest('id')->first();
    }

    public function lastContact(SalesInvoice $invoice): ?CollectionContact
    {
        return CollectionContact::query()->where('sales_invoice_id', $invoice->id)->latest('contacted_at')->latest('id')->first();
    }

    /**
     * Whether the latest promise was kept: true when the invoice is paid or receipts dated on or after the contact
     * settle at least what was promised (the balance at the contact when no amount was named); false when the
     * promised day has passed without that; null while the day is ahead, or when nothing was promised.
     */
    public function promiseKept(SalesInvoice $invoice): ?bool
    {
        $promise = $this->promise($invoice);
        if ($promise === null) {
            return null;
        }
        if ($invoice->payment_status === 'paid') {
            return true;
        }
        $dayBefore = $promise->contacted_at->copy()->subDay()->toDateString();
        $owedThen = $this->settlement->balanceAsOf($invoice, $dayBefore);
        $paidSince = $owedThen - $this->settlement->balance($invoice);
        $promised = $promise->promise_amount ?? $owedThen;
        if ($paidSince >= $promised && $promised > 0) {
            return true;
        }

        return $promise->promise_date->isBefore(today()) ? false : null;
    }

    /** Which bucket of the worklist an invoice sits in today. */
    public function bucket(SalesInvoice $invoice): string
    {
        $promise = $this->promise($invoice);
        if ($promise !== null) {
            $kept = $this->promiseKept($invoice);
            if ($kept === true) {
                return self::REST;
            }
            if ($promise->promise_date->isSameDay(today())) {
                return self::DUE_TODAY;
            }
            if ($kept === false) {
                return self::MISSED;
            }

            return self::REST;
        }
        $due = $invoice->due_date ?? $invoice->trans_date;

        return $due->isBefore(today()) ? self::UNCONTACTED : self::REST;
    }

    public static function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            self::DUE_TODAY => __('Promised for today'),
            self::MISSED => __('Promise missed'),
            self::UNCONTACTED => __('Overdue, no promise'),
            self::REST => __('The rest'),
            default => $bucket,
        };
    }

    /** @return array<string, list<int>> bucket → invoice ids */
    public function worklist(User $user): array
    {
        $out = [self::DUE_TODAY => [], self::MISSED => [], self::UNCONTACTED => [], self::REST => []];
        foreach ($this->chaseable($user)->orderBy('trans_date')->get() as $invoice) {
            if ($this->approvals->isApproved($invoice)) {
                $out[$this->bucket($invoice)][] = (int) $invoice->id;
            }
        }

        return $out;
    }
}
