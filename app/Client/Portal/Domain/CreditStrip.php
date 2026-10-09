<?php

declare(strict_types=1);

namespace App\Client\Portal\Domain;

use App\Client\Domain\Debt\DebtNotices;
use App\Domain\Sales\CreditCheck;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use Carbon\CarbonInterface;

/**
 * What a buyer sees above every page: free credit, what they owe, what is
 * on order, and the state of their account — fine, a notice (an invoice past
 * the notice days, with the day the account freezes) or frozen.
 */
final class CreditStrip
{
    public function __construct(private readonly CreditCheck $credit, private readonly CartEstimate $estimate, private readonly DebtNotices $notices) {}

    /** @return array{free_credit: ?int, exposure: int, open_orders: int, oldest_days: int, state: string, freezes_on: ?CarbonInterface, oldest: ?SalesInvoice} */
    public function of(Customer $customer): array
    {
        $oldestDays = $this->credit->oldestUnpaidDays($customer);
        $oldest = SalesInvoice::query()->where('customer_id', $customer->id)->where('payment_status', '!=', 'paid')->orderBy('trans_date')->first();
        $freeze = $this->credit->freezeDays();
        $notice = $this->credit->noticeDays();
        $state = match (true) {
            $freeze > 0 && $oldestDays > $freeze => 'frozen',
            $notice > 0 && $oldestDays > $notice => 'notice',
            default => 'fine',
        };

        return [
            'free_credit' => $this->estimate->freeCredit($customer),
            'exposure' => $this->credit->exposure($customer),
            'open_orders' => $this->credit->openOrders($customer),
            'oldest_days' => $oldestDays,
            'state' => $state,
            'freezes_on' => $oldest !== null && $state === 'notice' ? $this->notices->freezesOn($oldest) : null,
            'oldest' => $oldest,
        ];
    }
}
