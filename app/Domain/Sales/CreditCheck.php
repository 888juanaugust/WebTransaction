<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Access\HakAkses;
use App\Domain\Access\HakKhusus;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Sales\Contracts\AgingDate;
use App\Domain\Settlement\SettlementService;
use App\Domain\Shared\Format;
use App\Models\Company\OpeningBalance;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesDownPayment;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Whether a customer may take on more credit: the limit by amount (open
 * receivables plus open orders) and the limit by age (an unpaid invoice older
 * than N days), each only when the customer has it switched on; the company's
 * own notice and freeze day counts from the Business Rules (0 = off); and the
 * override right that lifts the amount limit. Derived arithmetic every time,
 * never stored state.
 */
final class CreditCheck
{
    public function __construct(private readonly SettlementService $settlement, private readonly HakAkses $akses, private readonly Preferensi $prefs, private readonly AgingDate $aging) {}

    /** Days after which an unpaid invoice flags the customer; 0 when the rule is off. */
    public function noticeDays(): int
    {
        return max(0, (int) $this->prefs->get(PreferensiKey::CreditNoticeDays));
    }

    /** Days after which an unpaid invoice freezes the customer; 0 when the rule is off. */
    public function freezeDays(): int
    {
        return max(0, (int) $this->prefs->get(PreferensiKey::CreditFreezeDays));
    }

    /** Open receivables (invoices, down payments and opening balances, less credits) of the customer or its parent group. */
    public function exposure(Customer $customer): int
    {
        $ids = $this->groupIds($customer);
        $open = 0;
        foreach (SalesInvoice::query()->whereIn('customer_id', $ids)->where('payment_status', '!=', 'paid')->get() as $invoice) {
            $open += $this->settlement->balance($invoice);
        }
        foreach (SalesDownPayment::query()->whereIn('customer_id', $ids)->where('payment_status', '!=', 'paid')->get() as $dp) {
            $open += $this->settlement->balance($dp);
        }
        foreach (SalesReturn::query()->whereIn('customer_id', $ids)->where('payment_status', '!=', 'paid')->get() as $return) {
            $open += $this->settlement->balance($return);
        }
        foreach ($this->openings($ids)->get() as $opening) {
            $open += $this->settlement->balance($opening);
        }

        return $open;
    }

    /** Approved orders not yet invoiced, by their remaining value. */
    public function openOrders(Customer $customer, ?int $excludeOrderId = null): int
    {
        $ids = $this->groupIds($customer);
        $open = 0;
        foreach (SalesOrder::query()->whereIn('customer_id', $ids)->whereIn('status', ['pending', 'partial'])->where('approval_status', 'approved')->when($excludeOrderId, fn ($q) => $q->whereKeyNot($excludeOrderId))->with('lines')->get() as $order) {
            $open += $order->remainingValue();
        }

        return $open;
    }

    /** The oldest unpaid invoice's age in days, by the aging basis in Preferences. */
    public function oldestUnpaidDays(Customer $customer): int
    {
        $basis = $this->prefs->get(PreferensiKey::AgingBasis);
        $column = $basis === 'due_date' ? 'due_date' : $this->aging->issuedColumn();
        $oldest = collect([
            SalesInvoice::query()->whereIn('customer_id', $this->groupIds($customer))->where('payment_status', '!=', 'paid')->min(DB::raw($column)),
            $this->openings($this->groupIds($customer))->min($basis === 'due_date' ? 'due_date' : 'document_date'),
        ])->filter()->min();

        return $oldest ? max(0, (int) Carbon::parse($oldest)->diffInDays(today(), false)) : 0;
    }

    /** @param  list<int>  $ids */
    private function openings(array $ids): Builder
    {
        return OpeningBalance::query()->where('party_type', (new Customer)->getMorphClass())->whereIn('party_id', $ids)->where('payment_status', '!=', 'paid');
    }

    /** @throws RuntimeException when the customer must not take on $newAmount more */
    public function assert(Customer $customer, int $newAmount, ?int $excludeOrderId = null): void
    {
        $limitHolder = $customer->credit_limit_mode === 'parent' && $customer->parentCustomer ? $customer->parentCustomer : $customer;
        $age = $this->oldestUnpaidDays($customer);

        $freeze = $this->freezeDays();
        if ($freeze > 0 && $age > $freeze) {
            throw new RuntimeException(__(':name is frozen: an invoice has been unpaid for :age days (the limit is :freeze). Settle it first.', ['name' => $customer->name, 'age' => $age, 'freeze' => $freeze]));
        }
        if ($limitHolder->credit_limit_age_enabled && $age > $limitHolder->credit_limit_age_days) {
            throw new RuntimeException(__(':name has an invoice unpaid for :age days, over its :credit_limit_age_days-day limit.', ['name' => $customer->name, 'age' => $age, 'credit_limit_age_days' => $limitHolder->credit_limit_age_days]));
        }
        if ($limitHolder->credit_limit_amount_enabled) {
            $would = $this->exposure($customer) + $this->openOrders($customer, $excludeOrderId) + $newAmount;
            if ($would > $limitHolder->credit_limit_amount && ! $this->akses->allowsSpecial(auth()->user(), HakKhusus::OverrideCreditLimit)) {
                throw new RuntimeException(sprintf('%s would owe %s with this order, over its credit limit of %s.', $customer->name, Format::rupiah($would), Format::rupiah((int) $limitHolder->credit_limit_amount)));
            }
        }
    }

    public function needsNotice(Customer $customer): bool
    {
        $notice = $this->noticeDays();

        return $notice > 0 && $this->oldestUnpaidDays($customer) > $notice;
    }

    /** @return list<int> */
    private function groupIds(Customer $customer): array
    {
        if ($customer->credit_limit_mode === 'parent' && $customer->parent_customer_id) {
            return Customer::query()->where('parent_customer_id', $customer->parent_customer_id)->orWhereKey($customer->parent_customer_id)->pluck('id')->all();
        }

        return [$customer->id];
    }
}
