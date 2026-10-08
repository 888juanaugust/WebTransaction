<?php

namespace Tests\Feature;

use App\Domain\Posting\AccountBalances;
use App\Filament\Pages\Budgeting\BudgetMonitor;
use App\Filament\Pages\Company\Calendar;
use App\Filament\Resources\Budgeting\Budgets\Pages\CreateBudget;
use App\Filament\Resources\Company\RecurringTransactions\Pages\ListRecurringTransactions;
use App\Filament\Resources\GeneralLedger\PayrollEntries\Pages\CreatePayrollEntry;
use App\Models\Budgeting\Budget;
use App\Models\Company\PayrollEntry;
use App\Models\Company\RecurringTransaction;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalVoucher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyExtrasScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->enableAllModules();
        $this->seed();
        $this->actingAsAdmin();
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    public function test_budgets_payroll_recurring_and_the_calendar_run_through_the_screens(): void
    {
        Livewire::test(CreateBudget::class)
            ->fillForm(['year' => 2026, 'month' => 11, 'scope' => 'general', 'analyst_name' => 'Finance', 'lines' => [['account_id' => $this->account('6200'), 'amount' => 5_000_000]]])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(5_000_000, (int) Budget::query()->firstOrFail()->lines()->sum('amount'));

        Livewire::test(BudgetMonitor::class)->set('filters.year', 2026)->set('filters.month', 11)->assertSee('Rent')->assertSee('5.000.000');

        $employee = $this->sampleEmployee(['is_salesman' => false]);
        Livewire::test(CreatePayrollEntry::class)
            ->fillForm([
                'payment_type' => 'monthly', 'period_month' => 11, 'period_year' => 2026, 'trans_date' => '2026-11-25', 'due_date' => '2026-11-30',
                'expense_payable_account_id' => $this->account('2230'), 'tax_payable_account_id' => $this->account('2220'),
                'lines' => [['employee_id' => $employee->id, 'gross_amount' => 8_000_000, 'income_tax' => 250_000, 'net_amount' => 7_750_000]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $entry = PayrollEntry::query()->firstOrFail();
        $this->assertSame('PAY-2611-0001', $entry->number);
        $this->assertSame(7_750_000, $entry->total);
        $this->assertSame(8_000_000, AccountBalances::asOf()[$this->account('6100')]);

        $recurring = RecurringTransaction::query()->create([
            'name' => 'Rent accrual', 'transaction_type' => 'journal_voucher', 'frequency' => 'monthly', 'next_run_on' => '2026-11-01', 'status' => 'active', 'created_by' => auth()->id(),
            'template' => ['description' => 'Rent', 'lines' => [['account_id' => $this->account('6200'), 'debit' => 1_000_000, 'credit' => 0], ['account_id' => $this->account('2230'), 'debit' => 0, 'credit' => 1_000_000]]],
        ]);
        Livewire::test(ListRecurringTransactions::class)->callTableAction('run', $recurring)->assertHasNoTableActionErrors();
        $this->assertSame(1, JournalVoucher::query()->count());
        $this->assertSame('2026-12-01', $recurring->fresh()->next_run_on->toDateString());

        Livewire::test(Calendar::class)->assertSee('November 2026')->assertSee('Month end')
            ->call('nextMonth')->assertSee('December 2026')->assertSee('Recurring: Rent accrual');
    }
}
