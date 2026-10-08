<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Posting\AccountBalances;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Money;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Sales\SalesInvoice;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The dashboard's KPI row (DESIGN.md page type D): this month's invoiced
 * sales against last month's, what customers owe, what the company owes,
 * and the cash and bank balance, all read from the invoices and the journal.
 */
class CompanyPulse extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    /** Rendered with the page, not after it: the figures are the first thing the owner reads. */
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /** Company-wide figures: for someone who may read the reports and is not limited to some branches. */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && Branch::limitsOf($user) === null && app(HakAkses::class)->allows($user, MenuKey::ReportCatalogue, Hak::View);
    }

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $thisMonth = (int) SalesInvoice::query()->whereBetween('trans_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])->sum('total');
        $lastMonth = (int) SalesInvoice::query()->whereBetween('trans_date', [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()])->sum('total');
        $change = $lastMonth > 0 ? (int) round(($thisMonth - $lastMonth) * 100 / $lastMonth) : null;

        $balances = AccountBalances::asOf($today);
        $sum = fn (AccountType $type): int => Account::query()->ofType($type)->whereNull('parent_id')->pluck('id')->sum(fn ($id) => $balances[$id] ?? 0);
        $openReceivables = SalesInvoice::query()->where('payment_status', '!=', 'paid')->count();
        $overdue = SalesInvoice::query()->where('payment_status', '!=', 'paid')->whereDate('due_date', '<', $today)->count();
        $openPayables = PurchaseInvoice::query()->where('payment_status', '!=', 'paid')->count();

        return [
            Stat::make(__('Sales this month'), Money::rupiah($thisMonth))
                ->description($change === null ? __('nothing invoiced last month') : __(':change % vs last month', ['change' => ($change >= 0 ? '+' : '').$change]))
                ->descriptionIcon($change === null ? 'heroicon-m-minus' : ($change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'))
                ->color($change === null ? 'gray' : ($change >= 0 ? 'success' : 'danger')),
            Stat::make(__('Receivables outstanding'), Money::rupiah($sum(AccountType::AccountsReceivable)))
                ->description(__(':count invoice(s) open', ['count' => $openReceivables]).($overdue ? __(', :count overdue', ['count' => $overdue]) : ''))
                ->color($overdue ? 'warning' : 'gray'),
            Stat::make(__('Payables outstanding'), Money::rupiah($sum(AccountType::AccountsPayable)))
                ->description(__(':count bill(s) open', ['count' => $openPayables]))
                ->color('gray'),
            Stat::make(__('Cash and bank'), Money::rupiah($sum(AccountType::CashBank)))
                ->description(__('all accounts, today'))
                ->color('primary'),
        ];
    }
}
