<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Claims\ClaimStatus;
use App\Client\Domain\Claims\ExpenseClaims;
use App\Client\Filament\Resources\ExpenseClaims\Pages\CreateExpenseClaim;
use App\Client\Filament\Resources\ExpenseClaims\Pages\ViewExpenseClaim;
use App\Client\Models\ExpenseClaim;
use App\Domain\Shared\Enums\AccountType;
use App\Models\CashBank\CashPayment;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** A sales user's expenses on two keys: Sales files, Finance pays it as a cash payment on the expense account it chooses. */
class ExpenseClaimTest extends TestCase
{
    use OrderFlow;

    private Account $cash;

    private Account $freight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->cash = Account::query()->ofType(AccountType::CashBank)->orderBy('no')->firstOrFail();
        $this->freight = ExpenseClaims::defaultExpenseAccount();
    }

    private function claims(): ExpenseClaims
    {
        return app(ExpenseClaims::class);
    }

    public function test_sales_files_and_finance_pays_on_the_expense_account(): void
    {
        $this->assertSame('6300', $this->freight->no, 'Freight Out by default');

        $this->actingAs($this->sales);
        $claim = $this->claims()->file($this->sales, $this->customer, today()->toDateString(), 75_000, 'Ojek to drop the parts at the shop');
        $this->assertSame(ClaimStatus::FILED, $claim->status);
        $this->assertSame($this->customer->branch_id, $claim->branch_id);

        $this->actingAs($this->finance);
        $payment = $this->claims()->verify($claim, $this->finance, $this->freight->id, $this->cash->id);

        $this->assertInstanceOf(CashPayment::class, $payment);
        $this->assertSame($this->finance->id, $payment->created_by);
        $this->assertSame(75_000, $payment->amount);
        $this->assertSame($this->sales->name, $payment->payee);
        $this->assertStringStartsWith('CB-JKT-', $payment->number);
        $this->assertSame(1, $payment->lines()->where('account_id', $this->freight->id)->count());
        $this->assertSame(75_000, (int) JournalLine::query()->where('account_id', $this->freight->id)->sum('debit'), 'the expense is booked');
        $this->assertSame(ClaimStatus::VERIFIED, $claim->fresh()->status);
        $this->assertSame($payment->id, $claim->fresh()->cash_payment_id);
    }

    public function test_only_sales_files_for_own_customers_with_a_past_date_and_an_amount(): void
    {
        try {
            $this->claims()->file($this->finance, null, today()->toDateString(), 10_000, 'x');
            $this->fail('finance filed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Sales group', $e->getMessage());
        }
        $other = $this->sampleCustomer(['name' => 'Somebody else', 'number' => 'C-X', 'branch_id' => $this->jakarta->id, 'sales_user_id' => $this->member(CentralGroups::SALES, [$this->jakarta])->id]);
        try {
            $this->claims()->file($this->sales, $other, today()->toDateString(), 10_000, 'x');
            $this->fail('not their customer');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not one of your customers', $e->getMessage());
        }
        try {
            $this->claims()->file($this->sales, null, today()->addDay()->toDateString(), 10_000, 'x');
            $this->fail('future');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('future', $e->getMessage());
        }
        try {
            $this->claims()->file($this->sales, null, today()->toDateString(), 0, 'x');
            $this->fail('zero');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('above zero', $e->getMessage());
        }

        $road = $this->claims()->file($this->sales, null, today()->subDay()->toDateString(), 20_000, 'Fuel');
        $this->assertNull($road->customer_id);
        $this->assertNotNull($road->branch_id, 'road costs land in the filer\'s branch');
    }

    public function test_the_filer_never_verifies_and_a_sales_user_sees_only_their_own(): void
    {
        $claim = $this->claims()->file($this->sales, null, today()->toDateString(), 20_000, 'Fuel');
        $other = $this->member(CentralGroups::SALES, [$this->jakarta]);
        $theirs = $this->claims()->file($other, null, today()->toDateString(), 30_000, 'Parking');

        try {
            $this->claims()->verify($claim, $this->sales, $this->freight->id, $this->cash->id);
            $this->fail('sales verified');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Update right', $e->getMessage());
        }
        $this->assertSame([$claim->id], ExpenseClaim::query()->visibleTo($this->sales)->pluck('id')->all());
        $this->assertSame([$theirs->id], ExpenseClaim::query()->visibleTo($other)->pluck('id')->all());
        $this->assertCount(2, ExpenseClaim::query()->visibleTo($this->finance)->get());

        $this->actingAs($this->finance);
        $this->claims()->reject($claim, $this->finance, 'no receipt');
        $this->assertSame(ClaimStatus::REJECTED, $claim->fresh()->status);
        $this->assertSame(0, CashPayment::query()->count());
    }

    public function test_the_screens_file_and_pay(): void
    {
        $this->actingAs($this->sales);
        $this->get('/admin/client/expense-claims')->assertOk();
        Livewire::test(CreateExpenseClaim::class)
            ->fillForm(['trans_date' => today()->toDateString(), 'amount' => 40_000, 'customer_id' => $this->customer->id, 'description' => 'Delivery by ojek'])
            ->call('create')->assertHasNoFormErrors();
        $claim = ExpenseClaim::query()->sole();
        $this->assertSame($this->sales->id, $claim->sales_user_id);

        $this->actingAs($this->finance);
        Livewire::test(ViewExpenseClaim::class, ['record' => $claim->getRouteKey()])
            ->assertOk()->assertActionVisible('verify')
            ->callAction('verify', ['expense_account_id' => $this->freight->id, 'bank_account_id' => $this->cash->id])
            ->assertHasNoActionErrors();
        $this->assertSame(ClaimStatus::VERIFIED, $claim->fresh()->status);
        $this->assertSame(40_000, CashPayment::query()->sole()->amount);
    }
}
