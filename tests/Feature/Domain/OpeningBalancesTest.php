<?php

namespace Tests\Feature\Domain;

use App\Domain\Company\OpeningBalances;
use App\Domain\GeneralLedger\AccountOpenings;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\PeriodLock;
use App\Domain\Reports\Period;
use App\Domain\Reports\TradeReports;
use App\Domain\Sales\CreditCheck;
use App\Domain\Shared\RecordInUse;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Filament\Support\ReceivableFields;
use App\Models\Company\OpeningBalance;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\AccountOpeningBalance;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesReceipt;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** Customer and vendor opening balances are open invoices: posted on the data start, settled like invoices, aged from their own date. */
class OpeningBalancesTest extends TestCase
{
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-02-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        app(Preferensi::class)->set(PreferensiKey::DataStartDate, '2026-01-01');
        $this->customer = $this->sampleCustomer();
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[(int) Account::query()->where('no', $no)->value('id')] ?? 0;
    }

    private function openCustomer(int $amount = 11_100_000): OpeningBalance
    {
        app(OpeningBalances::class)->sync($this->customer, ['new-1' => ['document_date' => '2025-11-15', 'amount' => $amount, 'number' => 'INV-OLD-17']]);

        return OpeningBalance::query()->where('party_id', $this->customer->id)->sole();
    }

    private function receive(OpeningBalance $opening, int $amount): SalesReceipt
    {
        $receipt = SalesReceipt::query()->create(['number' => 'CB-1', 'trans_date' => '2026-01-10', 'customer_id' => $this->customer->id, 'bank_account_id' => Account::query()->where('no', '1102')->value('id'), 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'opening_balance', 'receivable_id' => $opening->id, 'amount' => $amount, 'discount' => 0]);
        $receipt->refreshTotal();
        app(DocumentRepository::class)->created($receipt);

        return $receipt;
    }

    public function test_an_opening_receivable_posts_on_the_data_start_and_is_audited(): void
    {
        $opening = $this->openCustomer();

        $this->assertSame('2026-01-01', $opening->trans_date->toDateString(), 'posted on the data start');
        $this->assertSame('2025-11-15', $opening->document_date->toDateString(), 'keeps its own date for aging');
        $this->assertSame('2025-12-15', $opening->due_date->toDateString(), 'from the 30-day payment term');
        $this->assertSame(11_100_000, $this->balance('1200'));
        $this->assertSame(11_100_000, $this->balance('3300'));
        $this->assertTrue(DB::table('document_revisions')->where('document_type', 'opening_balance')->where('document_id', $opening->id)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'created')->where('reference', 'INV-OLD-17')->exists());

        $this->expectException(RuntimeException::class);
        app(OpeningBalances::class)->sync($this->customer, ['new-1' => ['document_date' => '2026-01-05', 'amount' => 1_000]]);
    }

    public function test_a_receipt_settles_it_and_a_settled_row_is_locked(): void
    {
        $opening = $this->openCustomer();
        $this->assertArrayHasKey('opening_balance:'.$opening->id, ReceivableFields::openFor($this->customer->id)->all());

        $this->receive($opening, 11_100_000);
        $this->assertSame('paid', $opening->fresh()->payment_status);
        $this->assertSame(0, $this->balance('1200'));

        try {
            app(OpeningBalances::class)->sync($this->customer, ['record-'.$opening->id => ['document_date' => '2025-11-15', 'amount' => 9_000_000, 'number' => 'INV-OLD-17']]);
            $this->fail('a settled opening balance changed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('payments have been applied', $e->getMessage());
        }
        $this->assertSame(11_100_000, $opening->fresh()->amount);
    }

    public function test_aging_statements_and_the_credit_check_count_opening_items(): void
    {
        $opening = $this->openCustomer();
        $this->receive($opening, 4_000_000);

        $aging = collect(TradeReports::receivableAging(new Period('2026-01-01', '2026-02-16')))->firstWhere('id', $this->customer->id);
        $this->assertSame(7_100_000, $aging['total']);
        $this->assertSame(93, $aging['oldest_days'], 'from 15 Nov 2025');

        $statement = TradeReports::statement('customer', $this->customer->id, new Period('2026-01-01', '2026-02-16'));
        $this->assertSame([0, 11_100_000, 7_100_000, 7_100_000], array_column($statement, 'balance'));
        $this->assertSame(7_100_000, app(CreditCheck::class)->exposure($this->customer));
        $this->assertSame(93, app(CreditCheck::class)->oldestUnpaidDays($this->customer));
    }

    public function test_vendor_opening_balances_are_payables_settled_by_payments(): void
    {
        $vendor = $this->sampleVendor();
        app(OpeningBalances::class)->sync($vendor, ['new-1' => ['document_date' => '2025-12-20', 'amount' => 5_000_000]]);
        $opening = OpeningBalance::query()->where('party_type', (new Vendor)->getMorphClass())->sole();
        $this->assertSame(5_000_000, $this->balance('2100'));
        $this->assertSame(-5_000_000, $this->balance('3300'));

        $payment = PurchasePayment::query()->create(['number' => 'CB-2', 'trans_date' => '2026-01-12', 'vendor_id' => $vendor->id, 'bank_account_id' => Account::query()->where('no', '1102')->value('id'), 'payment_method' => 'bank_transfer', 'created_by' => auth()->id()]);
        $payment->lines()->create(['sort' => 0, 'payable_type' => 'opening_balance', 'payable_id' => $opening->id, 'amount' => 5_000_000, 'discount' => 0]);
        $payment->refreshTotal();
        app(DocumentRepository::class)->created($payment);
        $this->assertSame('paid', $opening->fresh()->payment_status);
        $this->assertSame(0, $this->balance('2100'));
    }

    public function test_the_form_saves_rows_through_the_repository_and_a_closed_month_refuses(): void
    {
        Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])
            ->set('data.openingBalances', ['new-a' => ['document_date' => '2025-10-01', 'amount' => '2500000', 'number' => 'INV-OLD-1']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(2_500_000, $this->balance('1200'));

        app(PeriodLock::class)->close(2026, 1);
        $opening = OpeningBalance::query()->sole();
        Livewire::test(EditCustomer::class, ['record' => $this->customer->getRouteKey()])
            ->set('data.openingBalances', ['record-'.$opening->id => ['document_date' => '2025-10-01', 'amount' => '3000000', 'number' => 'INV-OLD-1']])
            ->call('save')
            ->assertNotified('Opening balances not saved');
        $this->assertSame(2_500_000, $opening->fresh()->amount);
    }

    public function test_the_command_posts_rows_saved_before_and_runs_twice_safely(): void
    {
        $legacy = OpeningBalance::query()->create(['party_type' => (new Customer)->getMorphClass(), 'party_id' => $this->customer->id, 'trans_date' => '2025-09-30', 'amount' => 1_000_000, 'number' => 'OLD-9']);

        $this->artisan('erp:post-opening-balances')->expectsOutputToContain('posted: 1')->assertSuccessful();
        $this->assertSame('2026-01-01', $legacy->fresh()->trans_date->toDateString());
        $this->assertSame('2025-09-30', $legacy->fresh()->document_date->toDateString());
        $this->assertSame(1_000_000, $this->balance('1200'));
        $this->artisan('erp:post-opening-balances')->expectsOutputToContain('posted: 0')->assertSuccessful();
        $this->assertSame(1_000_000, $this->balance('1200'));
    }

    public function test_a_customer_with_opening_balances_cannot_be_deleted(): void
    {
        $this->openCustomer();
        $this->expectException(RecordInUse::class);
        $this->customer->delete();
    }

    public function test_account_openings_are_documents_and_receivable_accounts_take_theirs_per_customer(): void
    {
        $bank = Account::query()->where('no', '1102')->firstOrFail();
        app(AccountOpenings::class)->save($bank, 25_000_000, null);
        $opening = AccountOpeningBalance::query()->sole();
        $this->assertSame('2026-01-01', $opening->trans_date->toDateString());
        $this->assertSame(25_000_000, $this->balance('1102'));
        $this->assertTrue(DB::table('document_revisions')->where('document_type', 'account_opening_balance')->exists());

        app(AccountOpenings::class)->save($bank, 30_000_000, null);
        $this->assertSame(30_000_000, $this->balance('1102'));
        app(AccountOpenings::class)->save($bank, 0, null);
        $this->assertSame(0, $this->balance('1102'));
        $this->assertNull(AccountOpeningBalance::query()->first());

        $this->expectException(RuntimeException::class);
        app(AccountOpenings::class)->save(Account::query()->where('no', '1200')->firstOrFail(), 1_000, null);
    }

    public function test_modules_register_their_own_schedule(): void
    {
        $events = collect(app(Schedule::class)->events());
        $commands = $events->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('erp:depreciate', $commands);
        $this->assertStringContainsString('erp:recurring', $commands);
        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping && $event->onOneServer, "{$event->command} runs once at a time, on one server");
        }
    }
}
