<?php

declare(strict_types=1);

namespace App\Domain\Credit;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How old a debt is, and what its age does to the customer.
 *
 * The organisation's two thresholds, counted from the invoice's issue date
 * because that is when the debt began — the due date printed on the faktur
 * (default 30 days) is a promise about it, not its birthday:
 *
 * - **120 days** — the customer is reminded, and so are the sales and
 *   marketing in charge of them. Once per invoice, marked on the row.
 * - **past 150 days** — jatuh tempo keras. The customer can make no new
 *   transaction until the aged invoice is settled. Not a status column:
 *   fall-due is calendar arithmetic over open invoices, recomputed whenever
 *   asked, so it can never say frozen about a debt paid an hour ago.
 */
class DebtAging
{
    /**
     * The invoices freezing this customer, oldest first. Empty means free to
     * trade.
     *
     * @return Collection<int, Invoice>
     */
    public function fallDueInvoices(Company $company): Collection
    {
        // Across regions: a split order books the debt wherever it shipped
        // from, and an aged invoice freezes the customer everywhere.
        return $this->openAgedQuery($this->freezeCutoff())
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->orderBy('issued_on')
            ->get();
    }

    public function isFrozen(Company $company): bool
    {
        return $this->openAgedQuery($this->freezeCutoff())
            ->withoutGlobalScope('region')
            ->where('company_id', $company->id)
            ->exists();
    }

    /**
     * Open invoices old enough for the three-month reminder and not yet
     * reminded about. Region-scoped like every query — the nightly sweep
     * walks the regions and asks once per region.
     *
     * @return Collection<int, Invoice>
     */
    public function needingNotice(): Collection
    {
        return $this->openAgedQuery($this->noticeCutoff())
            ->whereNull('debt_notified_at')
            ->with(['company.salesRep', 'company.marketingRep'])
            ->orderBy('issued_on')
            ->get();
    }

    /** The last issue date old enough to be frozen: strictly older than 150 days. */
    public function freezeCutoff(): Carbon
    {
        return today()
            ->subDays((int) config('penjualan.debt_freeze_days'))
            ->subDay();
    }

    /** The last issue date old enough for the reminder. */
    public function noticeCutoff(): Carbon
    {
        return today()->subDays((int) config('penjualan.debt_notice_days'));
    }

    /** @return Builder<Invoice> */
    private function openAgedQuery(Carbon $cutoff): Builder
    {
        return Invoice::query()
            ->where('status', Invoice::STATUS_OPEN)
            ->whereDate('issued_on', '<=', $cutoff->toDateString());
    }
}
