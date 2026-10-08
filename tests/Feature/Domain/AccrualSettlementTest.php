<?php

namespace Tests\Feature\Domain;

use App\Domain\CashBank\GiroService;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\Exceptions\DocumentLockedException;
use App\Filament\Resources\CashBank\CashPayments\Pages\CreateCashPayment;
use App\Models\CashBank\CashPayment;
use App\Models\Company\PayrollEntry;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\ExpenseAccrual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** An expense accrual or a payroll entry is paid by payment lines that settle it, through the allocation ledger. */
class AccrualSettlementTest extends TestCase
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

    private function accrual(int $amount): ExpenseAccrual
    {
        $accrual = ExpenseAccrual::query()->create(['number' => 'EA-1', 'trans_date' => '2026-11-01', 'due_date' => '2026-11-30', 'payable_account_id' => $this->account('2230'), 'description' => 'Rent', 'created_by' => auth()->id()]);
        $accrual->lines()->create(['sort' => 0, 'account_id' => $this->account('6200'), 'amount' => $amount, 'memo' => 'Rent']);
        $accrual->refreshTotal();
        $this->docs->created($accrual);

        return $accrual->fresh();
    }

    /** @param  array<string, mixed>  $header */
    private function pay(ExpenseAccrual|PayrollEntry $document, int $amount, array $header = []): CashPayment
    {
        $payment = CashPayment::query()->create(['number' => 'CB-'.uniqid(), 'trans_date' => '2026-11-10', 'bank_account_id' => $this->account('1102'), 'created_by' => auth()->id(), ...$header]);
        $payment->lines()->create(['sort' => 0, 'account_id' => $this->account('6500'), 'payable_type' => $document->getMorphClass(), 'payable_id' => $document->id, 'amount' => $amount]);
        $payment->refreshTotal();
        $this->docs->created($payment);

        return $payment->fresh();
    }

    public function test_an_accrual_is_paid_in_part_then_in_full_against_its_own_payable_account(): void
    {
        $accrual = $this->accrual(4_500_000);
        $this->assertSame('unpaid', $accrual->payment_status);
        $this->assertSame(4_500_000, $this->balance('2230'));

        $this->pay($accrual, 2_000_000);
        $this->assertSame(2_000_000, $accrual->fresh()->paid_amount);
        $this->assertSame('partial', $accrual->fresh()->payment_status);
        $this->assertSame(2_500_000, $this->balance('2230'), 'the line debits the accrual\'s payable account, not the account typed on it');
        $this->assertSame(0, $this->balance('6500'));

        $this->pay($accrual, 2_500_000);
        $this->assertSame('paid', $accrual->fresh()->payment_status);
        $this->assertSame(0, $this->balance('2230'));
        $this->assertSame(-4_500_000, $this->balance('1102'));
    }

    public function test_a_payment_never_settles_more_than_is_open(): void
    {
        $accrual = $this->accrual(1_000_000);
        $this->pay($accrual, 600_000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('400.000 open');
        $this->pay($accrual, 500_000);
    }

    public function test_deleting_the_payment_reopens_the_accrual_and_a_paid_accrual_is_locked(): void
    {
        $accrual = $this->accrual(1_000_000);
        $payment = $this->pay($accrual, 1_000_000);
        $this->assertSame('paid', $accrual->fresh()->payment_status);

        try {
            $this->docs->delete($accrual->fresh());
            $this->fail('a paid accrual cannot be deleted');
        } catch (DocumentLockedException $e) {
            $this->assertStringContainsString('payments', $e->getMessage());
        }

        $this->docs->delete($payment);
        $this->assertSame('unpaid', $accrual->fresh()->payment_status);
        $this->assertSame(0, $accrual->fresh()->paid_amount);
        $this->assertSame(1_000_000, $this->balance('2230'));
    }

    public function test_a_bounced_giro_reopens_the_accrual(): void
    {
        $accrual = $this->accrual(1_000_000);
        $payment = $this->pay($accrual, 1_000_000, ['cheque_no' => 'GR-77', 'cheque_date' => '2026-11-20']);
        $this->assertSame('paid', $accrual->fresh()->payment_status, 'a giro paid the accrual while it is outstanding');
        $this->assertSame(1_000_000, $this->balance('2105'), 'and waits in giros payable');

        $this->actingAsAdmin();
        app(GiroService::class)->bounce($payment->giro, '2026-11-21', 'Insufficient funds');
        $this->assertSame('unpaid', $accrual->fresh()->payment_status);
        $this->assertSame(1_000_000, $this->balance('2230'));
    }

    public function test_the_net_pay_of_a_payroll_entry_is_settled(): void
    {
        $employee = $this->sampleEmployee(['is_salesman' => false]);
        $entry = PayrollEntry::query()->create(['number' => 'PR-1', 'payment_type' => 'monthly', 'period_year' => 2026, 'period_month' => 11, 'trans_date' => '2026-11-25', 'due_date' => '2026-11-30', 'expense_payable_account_id' => $this->account('2230'), 'tax_payable_account_id' => $this->account('2220'), 'created_by' => auth()->id()]);
        $entry->lines()->create(['sort' => 0, 'employee_id' => $employee->id, 'gross_amount' => 8_000_000, 'income_tax' => 250_000, 'net_amount' => 7_750_000]);
        $entry->refreshTotal();
        $this->docs->created($entry);

        $this->pay($entry->fresh(), 7_750_000);
        $this->assertSame('paid', $entry->fresh()->payment_status);
        $this->assertSame(7_750_000, $entry->fresh()->paid_amount);
        $this->assertSame(0, $this->balance('2230'));
        $this->assertSame(250_000, $this->balance('2220'), 'the tax withheld stays owed to the tax office');
    }

    public function test_the_pay_action_opens_a_payment_that_settles_what_is_open(): void
    {
        $accrual = $this->accrual(3_000_000);
        $this->pay($accrual, 1_000_000);

        Livewire::withQueryParams(['settle' => 'expense_accrual:'.$accrual->id])
            ->test(CreateCashPayment::class)
            ->assertSchemaStateSet(['description' => 'Payment of EA-1'])
            ->fillForm(['bank_account_id' => $this->account('1102')])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('paid', $accrual->fresh()->payment_status);
        $line = CashPayment::query()->latest('id')->firstOrFail()->lines()->firstOrFail();
        $this->assertSame(2_000_000, $line->amount);
        $this->assertSame($this->account('2230'), $line->account_id);
    }
}
