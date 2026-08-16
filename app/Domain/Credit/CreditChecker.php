<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Credit exposure = unpaid invoices + confirmed orders not yet invoiced.
 *
 * Counting only invoices would let a customer confirm ten orders before any of
 * them are invoiced and walk straight through their limit, so orders that have
 * been confirmed but not yet billed count against it too.
 */
class CreditChecker
{
    public function __construct(private readonly OutstandingReceivables $receivables) {}

    /**
     * Check whether an order fits inside the customer's remaining credit.
     *
     * The order's own amount is excluded from `committed` so re-checking an
     * already-confirmed order doesn't count it twice.
     */
    public function check(Order $order): CreditStatus
    {
        $company = $order->company;

        return $this->evaluate(
            company: $company,
            orderAmount: $order->total_rupiah,
            excludeOrderId: $order->id,
            overdueCheck: true,
        );
    }

    /** Current position with no particular order in mind. */
    public function status(Company $company): CreditStatus
    {
        return $this->evaluate($company, orderAmount: 0, excludeOrderId: null, overdueCheck: false);
    }

    public function available(Company $company): int
    {
        return $this->status($company)->available();
    }

    private function evaluate(
        Company $company,
        int $orderAmount,
        ?int $excludeOrderId,
        bool $overdueCheck,
    ): CreditStatus {
        $outstanding = $this->outstanding($company);
        $committed = $this->committed($company, $excludeOrderId);

        $blockers = [];

        if (! $company->isActive()) {
            $blockers[] = "Pelanggan berstatus {$company->status}, belum boleh order kredit.";
        }

        $available = $company->credit_limit_rupiah - $outstanding - $committed;

        if ($orderAmount > $available) {
            $blockers[] = sprintf(
                'Melebihi limit kredit: butuh %s, tersedia %s.',
                number_format($orderAmount, 0, ',', '.'),
                number_format($available, 0, ',', '.'),
            );
        }

        if ($overdueCheck) {
            $overdue = $this->overdueCount($company);

            if ($overdue > 0) {
                $blockers[] = "Ada {$overdue} faktur jatuh tempo yang belum dibayar.";
            }
        }

        return new CreditStatus(
            limit: $company->credit_limit_rupiah,
            outstanding: $outstanding,
            committed: $committed,
            orderAmount: $orderAmount,
            blockers: $blockers,
        );
    }

    /**
     * Open invoices, net of everything posted against them in the payment
     * ledger — including reversals, which are just negative entries.
     */
    /**
     * What this customer owes, from the one definition of it.
     *
     * Used to sum invoices and subtract only *matched* payments, which meant
     * money sitting in the bank against an unallocated transfer did not free
     * up any credit — listed in docs/MAP.md as conservative but wrong. It also
     * meant this and the ledger's Piutang Usaha reconciliation, which never
     * had that filter, disagreed about the same customer.
     *
     * Both now read OutstandingReceivables, so there is one answer.
     */
    private function outstanding(Company $company): int
    {
        return $this->receivables->exposureFor($company);
    }

    /**
     * Confirmed orders that have not been invoiced yet. `paid` orders are not
     * counted — the money is already in.
     */
    private function committed(Company $company, ?int $excludeOrderId): int
    {
        return (int) Order::query()
            ->where('company_id', $company->id)
            ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::AwaitingPayment])
            ->whereDoesntHave('invoice')
            ->when($excludeOrderId !== null, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->sum('total_rupiah');
    }

    private function overdueCount(Company $company): int
    {
        return Invoice::query()
            ->where('company_id', $company->id)
            ->overdue()
            ->count();
    }
}
