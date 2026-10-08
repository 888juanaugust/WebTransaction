<?php

namespace Tests\Feature\Domain;

use App\Domain\Budgeting\BudgetMonitor;
use App\Domain\Company\CalendarFeed;
use App\Domain\Company\RecurringRunner;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Filament\Resources\Company\RecurringTransactions\RecurringTransactionResource;
use App\Models\Budgeting\Budget;
use App\Models\Budgeting\BudgetTransfer;
use App\Models\CashBank\CashPayment;
use App\Models\Company\Employee;
use App\Models\Company\PayrollEntry;
use App\Models\Company\RecurringTransaction;
use App\Models\Company\SalaryComponent;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompanyExtrasTest extends TestCase
{
    private DocumentRepository $docs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->enableAllModules();
        $this->seed();
        $this->actingAsAdmin();
        $this->docs = app(DocumentRepository::class);
    }

    private function account(string $no): int
    {
        return (int) Account::query()->where('no', $no)->value('id');
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[$this->account($no)] ?? 0;
    }

    public function test_a_budget_transfer_moves_the_amount_and_the_monitor_reads_the_journal(): void
    {
        $budget = Budget::query()->create(['year' => 2026, 'month' => 11, 'created_by' => auth()->id()]);
        $budget->lines()->createMany([
            ['sort' => 0, 'account_id' => $this->account('6200'), 'amount' => 5_000_000],
            ['sort' => 1, 'account_id' => $this->account('6500'), 'amount' => 1_000_000],
        ]);

        $payment = CashPayment::query()->create(['number' => 'CP-1', 'trans_date' => '2026-11-02', 'bank_account_id' => $this->account('1102'), 'payee' => 'Landlord', 'created_by' => auth()->id()]);
        $payment->lines()->create(['sort' => 0, 'account_id' => $this->account('6200'), 'amount' => 4_500_000, 'memo' => 'Rent']);
        $payment->refreshTotal();
        $this->docs->created($payment);

        $rows = collect(BudgetMonitor::rows(2026, 11));
        $rent = $rows->firstWhere('no', '6200');
        $this->assertSame(5_000_000, $rent['budget']);
        $this->assertSame(4_500_000, $rent['actual']);
        $this->assertSame(500_000, $rent['remaining']);
        $this->assertSame(90.0, $rent['used_percent']);
        $this->assertSame(6_000_000, $rows->firstWhere('id', 'total')['budget']);

        $transfer = BudgetTransfer::query()->create(['number' => 'BTR-1', 'year' => 2026, 'trans_date' => '2026-11-10', 'from_month' => 11, 'from_account_id' => $this->account('6500'), 'to_month' => 11, 'to_account_id' => $this->account('6200'), 'amount' => 400_000, 'created_by' => auth()->id()]);
        $this->docs->created($transfer);
        $this->assertSame(5_400_000, $budget->lines()->where('account_id', $this->account('6200'))->value('amount'));
        $this->assertSame(600_000, $budget->lines()->where('account_id', $this->account('6500'))->value('amount'));
        $this->assertSame(900_000, collect(BudgetMonitor::rows(2026, 11))->firstWhere('no', '6200')['remaining']);

        $december = BudgetTransfer::query()->create(['number' => 'BTR-2', 'year' => 2026, 'trans_date' => '2026-11-11', 'from_month' => 11, 'from_account_id' => $this->account('6500'), 'to_month' => 12, 'to_account_id' => $this->account('6500'), 'amount' => 100_000, 'created_by' => auth()->id()]);
        $this->docs->created($december);
        $this->assertSame(100_000, Budget::query()->where('month', 12)->firstOrFail()->lines()->value('amount'), 'a month without a budget gets one');

        $this->docs->delete($december->fresh());
        $this->assertSame(600_000, $budget->lines()->where('account_id', $this->account('6500'))->value('amount'), 'deleting a transfer gives the amount back');
        $this->assertSame(6_000_000, collect(BudgetMonitor::rows(2026))->firstWhere('id', 'total')['budget'], 'the year sums every month');
    }

    public function test_a_payroll_entry_posts_gross_pay_tax_withheld_and_net_owed(): void
    {
        $employee = $this->sampleEmployee(['is_salesman' => false]);
        $other = Employee::query()->create(['number' => 'EMP-00002', 'name' => 'Sari', 'is_salesman' => false]);
        $overtime = SalaryComponent::query()->where('name', 'Overtime')->firstOrFail();

        $entry = PayrollEntry::query()->create(['number' => 'PR-1', 'payment_type' => 'monthly', 'period_year' => 2026, 'period_month' => 11, 'trans_date' => '2026-11-25', 'due_date' => '2026-11-30', 'expense_payable_account_id' => $this->account('2230'), 'tax_payable_account_id' => $this->account('2220'), 'created_by' => auth()->id()]);
        $entry->lines()->createMany([
            ['sort' => 0, 'employee_id' => $employee->id, 'gross_amount' => 8_000_000, 'income_tax' => 250_000, 'net_amount' => 7_750_000],
            ['sort' => 1, 'employee_id' => $other->id, 'salary_component_id' => $overtime->id, 'gross_amount' => 1_000_000, 'income_tax' => 0, 'net_amount' => 1_000_000],
        ]);
        $entry->refreshTotal();
        $this->docs->created($entry);

        $this->assertSame(9_000_000, $entry->fresh()->gross_total);
        $this->assertSame(250_000, $entry->fresh()->tax_total);
        $this->assertSame(8_750_000, $entry->fresh()->total);
        $this->assertSame(9_000_000, $this->balance('6100'), 'both components book to salaries & wages');
        $this->assertSame(250_000, $this->balance('2220'));
        $this->assertSame(8_750_000, $this->balance('2230'));
    }

    public function test_a_month_end_schedule_stays_at_month_end_and_one_failure_stops_no_other(): void
    {
        $lines = [
            ['account_id' => $this->account('6200'), 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => $this->account('2230'), 'debit' => 0, 'credit' => 1_000_000],
        ];
        $monthEnd = RecurringTransaction::query()->create(['name' => 'Month-end accrual', 'transaction_type' => 'journal_voucher', 'frequency' => 'monthly',
            'next_run_on' => '2026-01-31', 'status' => 'active', 'created_by' => auth()->id(), 'template' => ['lines' => $lines]]);
        $broken = RecurringTransaction::query()->create(['name' => 'Unbalanced', 'transaction_type' => 'journal_voucher', 'frequency' => 'monthly',
            'next_run_on' => '2026-01-15', 'status' => 'active', 'created_by' => auth()->id(),
            'template' => ['lines' => [['account_id' => $this->account('6200'), 'debit' => 1, 'credit' => 0]]]]);

        $result = app(RecurringRunner::class)->runDue('2026-04-30');

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], JournalVoucher::query()->orderBy('trans_date')->get()->map(fn ($v) => $v->trans_date->toDateString())->all(),
            'February is the 28th; March is the 31st again');
        $this->assertSame('2026-05-31', $monthEnd->fresh()->next_run_on->toDateString());
        $this->assertCount(1, $result['failed'], 'the broken schedule is reported');
        $this->assertStringStartsWith('Unbalanced:', $result['failed'][0]);
        $this->assertSame('2026-01-15', $broken->fresh()->next_run_on->toDateString(), 'and left as it was');

        $this->artisan('erp:recurring', ['on' => '2026-04-30'])->assertFailed();
    }

    public function test_a_schedule_runs_only_as_someone_who_may_make_its_document(): void
    {
        $sales = User::factory()->create();
        AccessGroup::query()->where('name', 'Sales')->firstOrFail()->users()->attach($sales);
        $recurring = RecurringTransaction::query()->create(['name' => 'Rent', 'transaction_type' => 'journal_voucher', 'frequency' => 'monthly',
            'next_run_on' => '2026-11-01', 'status' => 'active', 'created_by' => $sales->id, 'template' => ['lines' => [
                ['account_id' => $this->account('6200'), 'debit' => 1_000, 'credit' => 0],
                ['account_id' => $this->account('2230'), 'debit' => 0, 'credit' => 1_000],
            ]]]);

        auth()->forgetUser(); // the morning schedule: made as the schedule's author, who may not make journal vouchers
        $result = app(RecurringRunner::class)->runDue('2026-11-15');
        $this->assertSame([], $result['made']);
        $this->assertStringContainsString('may not make', $result['failed'][0]);
        $this->assertSame(0, JournalVoucher::query()->count());

        // An author who has left runs nothing, whatever their rights were.
        $accountant = User::factory()->create();
        AccessGroup::query()->where('name', 'Accounting')->firstOrFail()->users()->attach($accountant);
        $recurring->forceFill(['created_by' => $accountant->id])->saveQuietly();
        $accountant->forceFill(['is_active' => false])->saveQuietly();
        $this->assertStringContainsString('no longer active', app(RecurringRunner::class)->runDue('2026-11-15')['failed'][0]);

        // Someone else's schedule is theirs to change only with the "edit other users' transactions" right.
        $other = User::factory()->create();
        AccessGroup::query()->where('name', 'Accounting')->firstOrFail()->users()->attach($other);
        $this->actingAs($other);
        $this->freshRequest();
        $this->assertFalse(RecurringTransactionResource::canEdit($recurring->fresh()));
        $this->assertFalse(RecurringTransactionResource::canDelete($recurring->fresh()));
    }

    public function test_a_recurring_journal_runs_on_its_dates_and_never_twice_for_one_date(): void
    {
        $recurring = RecurringTransaction::query()->create([
            'name' => 'Monthly rent accrual', 'category' => 'Accruals', 'transaction_type' => 'journal_voucher', 'frequency' => 'monthly',
            'next_run_on' => '2026-09-01', 'end_on' => '2026-11-30', 'status' => 'active', 'created_by' => auth()->id(),
            'template' => ['description' => 'Rent accrual', 'lines' => [
                ['account_id' => $this->account('6200'), 'debit' => 5_000_000, 'credit' => 0, 'memo' => 'Rent'],
                ['account_id' => $this->account('2230'), 'debit' => 0, 'credit' => 5_000_000, 'memo' => 'Rent'],
            ]],
        ]);

        $made = app(RecurringRunner::class)->runDue('2026-11-15')['made'];
        $this->assertCount(3, $made, 'September, October and November');
        $this->assertSame(3, JournalVoucher::query()->count());
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-11-01'], JournalVoucher::query()->orderBy('trans_date')->get()->map(fn ($v) => $v->trans_date->toDateString())->all());
        $this->assertSame('JV-2609-0001', JournalVoucher::query()->orderBy('trans_date')->first()->number);
        $this->assertSame(15_000_000, $this->balance('6200'));
        $this->assertSame('done', $recurring->fresh()->status, 'past its end date');
        $this->assertSame(3, $recurring->fresh()->run_count);

        $this->assertCount(0, app(RecurringRunner::class)->runDue('2026-11-15')['made'], 'nothing runs twice');
        $this->artisan('erp:recurring', ['on' => '2026-12-01'])->assertSuccessful();
        $this->assertSame(3, JournalVoucher::query()->count());

        $payment = RecurringTransaction::query()->create([
            'name' => 'Internet', 'transaction_type' => 'cash_payment', 'frequency' => 'monthly', 'next_run_on' => '2026-11-20', 'status' => 'active', 'created_by' => auth()->id(),
            'template' => ['description' => 'Internet subscription', 'bank_account_id' => $this->account('1102'), 'payee' => 'ISP', 'lines' => [['account_id' => $this->account('6500'), 'amount' => 750_000]]],
        ]);
        $doc = app(RecurringRunner::class)->run($payment, '2026-11-20');
        $this->assertInstanceOf(CashPayment::class, $doc);
        $this->assertSame(750_000, $doc->fresh()->amount);
        $this->assertSame('2026-12-20', $payment->fresh()->next_run_on->toDateString());
        $this->assertSame(-750_000, $this->balance('1102'));
    }

    public function test_the_calendar_shows_what_falls_due(): void
    {
        RecurringTransaction::query()->create(['name' => 'Rent', 'transaction_type' => 'journal_voucher', 'frequency' => 'monthly', 'next_run_on' => '2026-11-28', 'status' => 'active', 'template' => ['lines' => []], 'created_by' => auth()->id()]);
        $events = CalendarFeed::month(2026, 11);
        $this->assertSame('recurring', $events['2026-11-28'][0]['kind']);
        $this->assertSame('period', $events['2026-11-30'][0]['kind']);
    }
}
