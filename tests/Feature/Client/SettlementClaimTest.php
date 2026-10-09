<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Claims\ClaimStatus;
use App\Client\Domain\Claims\SettlementClaims;
use App\Client\Filament\Resources\SettlementClaims\Pages\CreateSettlementClaim;
use App\Client\Filament\Resources\SettlementClaims\Pages\ListSettlementClaims;
use App\Client\Filament\Resources\SettlementClaims\Pages\ViewSettlementClaim;
use App\Client\Models\SettlementClaim;
use App\Domain\Documents\PaymentMethod;
use App\Domain\Shared\Enums\AccountType;
use App\Models\Company\AuditLog;
use App\Models\GeneralLedger\Account;
use App\Models\Sales\SalesReceipt;
use App\Models\Settlement\PaymentAllocation;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** Pelunasan piutang on two keys: a seat files that the customer paid, Finance verifies it into a receipt; the filer never verifies. */
class SettlementClaimTest extends TestCase
{
    use OrderFlow;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50);
        $this->bank = Account::query()->ofType(AccountType::CashBank)->where('name', 'not ilike', '%cash%')->orderBy('no')->firstOrFail();
    }

    private function claims(): SettlementClaims
    {
        return app(SettlementClaims::class);
    }

    public function test_the_seat_files_and_finance_verifies_into_a_receipt_that_pays_the_invoice(): void
    {
        $invoice = $this->invoice(2, 100_000);
        $this->assertSame(222_000, $invoice->balance(), '12 % VAT on an 11/12 base');

        $this->actingAs($this->sales);
        $claim = $this->claims()->file($invoice, 222_000, 'Transfer BCA 16 Oct, slip on file', $this->sales);
        $this->assertSame(ClaimStatus::FILED, $claim->status);
        $this->assertSame($this->sales->id, $claim->filed_by);
        $this->assertSame($invoice->branch_id, $claim->branch_id);

        $this->actingAs($this->finance);
        $receipt = $this->claims()->verify($claim, $this->finance, $this->bank->id, today()->toDateString(), 'checked the slip');

        $this->assertInstanceOf(SalesReceipt::class, $receipt);
        $this->assertSame($this->finance->id, $receipt->created_by);
        $this->assertSame(222_000, $receipt->amount);
        $this->assertSame(PaymentMethod::BankTransfer, $receipt->payment_method);
        $this->assertStringStartsWith('CB-JKT-', $receipt->number);
        $this->assertSame(1, PaymentAllocation::query()->where('receivable_type', 'sales_invoice')->where('receivable_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->fresh()->payment_status, 'paid is set by settlement, never by the claim');
        $this->assertSame(0, $invoice->fresh()->balance());

        $claim = $claim->fresh();
        $this->assertSame(ClaimStatus::VERIFIED, $claim->status);
        $this->assertSame($receipt->id, $claim->sales_receipt_id);
        $this->assertSame($this->finance->id, $claim->decided_by);
        $this->assertSame('checked the slip', $claim->decision_note);
        $actions = AuditLog::query()->where('document_type', 'settlement_claim')->pluck('action')->all();
        $this->assertContains('claim_filed', $actions);
        $this->assertContains('claim_verified', $actions);
    }

    public function test_only_the_customers_seats_or_the_owner_file_within_the_balance_and_once(): void
    {
        $invoice = $this->invoice(1, 100_000);
        $stranger = $this->member(CentralGroups::SALES, [$this->jakarta]);

        try {
            $this->claims()->file($invoice, 50_000, 'cash', $stranger);
            $this->fail('a stranger filed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sales or marketing seat', $e->getMessage());
        }
        try {
            $this->claims()->file($invoice, $invoice->balance() + 1, 'cash', $this->marketing);
            $this->fail('above the balance');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('between 1 and the balance', $e->getMessage());
        }
        try {
            $this->claims()->file($invoice, 50_000, '  ', $this->marketing);
            $this->fail('no account');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('where, when and how', $e->getMessage());
        }

        $this->claims()->file($invoice, 50_000, 'cash to the driver', $this->marketing);
        try {
            $this->claims()->file($invoice, 10_000, 'again', $this->owner);
            $this->fail('two filed claims on one invoice');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has a claim', $e->getMessage());
        }
        $this->assertSame(1, SettlementClaim::query()->count());
    }

    public function test_whoever_files_never_verifies_and_sales_holds_no_key(): void
    {
        $invoice = $this->invoice(1, 100_000);
        $claim = $this->claims()->file($invoice, $invoice->balance(), 'transfer', $this->owner);

        try {
            $this->claims()->verify($claim, $this->owner, $this->bank->id, today()->toDateString());
            $this->fail('the owner verified their own claim');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('two keys, two people', $e->getMessage());
        }
        try {
            $this->claims()->verify($claim, $this->sales, $this->bank->id, today()->toDateString());
            $this->fail('sales verified');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Update right', $e->getMessage());
        }
        $this->assertSame(ClaimStatus::FILED, $claim->fresh()->status);
        $this->assertSame(0, SalesReceipt::query()->count());

        $this->actingAs($this->finance);
        try {
            $this->claims()->reject($claim, $this->finance, '');
            $this->fail('rejected without a note');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs a note', $e->getMessage());
        }
        $this->claims()->reject($claim, $this->finance, 'no such transfer on the statement');
        $this->assertSame(ClaimStatus::REJECTED, $claim->fresh()->status);
        try {
            $this->claims()->verify($claim->fresh(), $this->finance, $this->bank->id, today()->toDateString());
            $this->fail('decided twice');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('decided already', $e->getMessage());
        }
    }

    public function test_a_balance_that_shrank_since_filing_refuses_the_verification(): void
    {
        $invoice = $this->invoice(1, 100_000);
        $claim = $this->claims()->file($invoice, $invoice->balance(), 'transfer', $this->sales);

        $this->actingAs($this->finance);
        $early = $this->claims()->verify($this->claims()->file($this->invoice(1, 100_000), 1, 'x', $this->sales), $this->finance, $this->bank->id, today()->toDateString());
        $this->assertNotNull($early);
        $receipt = SalesReceipt::query()->create(['number' => 'CBV-x', 'trans_date' => today()->toDateString(), 'customer_id' => $this->customer->id, 'branch_id' => $invoice->branch_id, 'bank_account_id' => $this->bank->id, 'payment_method' => 'cash', 'amount' => 0, 'created_by' => $this->finance->id]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => $invoice->id, 'amount' => 100_000, 'discount' => 0]);
        $receipt->refreshTotal();
        $this->docs->created($receipt);
        $this->assertSame(11_000, $invoice->fresh()->balance());

        try {
            $this->claims()->verify($claim, $this->finance, $this->bank->id, today()->toDateString());
            $this->fail('verified above the balance');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('now below the claim', $e->getMessage());
        }
        $this->assertSame(ClaimStatus::FILED, $claim->fresh()->status);
    }

    public function test_the_screens_file_and_decide(): void
    {
        $invoice = $this->invoice(1, 100_000);

        $this->actingAs($this->sales);
        $this->get('/admin/client/settlement-claims')->assertOk();
        Livewire::test(CreateSettlementClaim::class)
            ->fillForm(['customer_id' => $this->customer->id, 'sales_invoice_id' => $invoice->id, 'amount' => $invoice->balance(), 'account' => 'transfer on the 16th'])
            ->call('create')->assertHasNoFormErrors();
        $claim = SettlementClaim::query()->sole();
        $this->assertSame($this->sales->id, $claim->filed_by);
        Livewire::test(ViewSettlementClaim::class, ['record' => $claim->getRouteKey()])->assertOk()->assertActionHidden('verify');

        $this->actingAs($this->finance);
        Livewire::test(ListSettlementClaims::class)->assertOk()->assertSee($invoice->number);
        Livewire::test(ViewSettlementClaim::class, ['record' => $claim->getRouteKey()])
            ->assertActionVisible('verify')
            ->callAction('verify', ['bank_account_id' => $this->bank->id, 'trans_date' => today()->toDateString()])
            ->assertHasNoActionErrors();
        $this->assertSame(ClaimStatus::VERIFIED, $claim->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
    }
}
