<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Debt\CollectionDesk;
use App\Client\Filament\Pages\Collections;
use App\Client\Models\CollectionContact;
use App\Domain\Shared\Enums\AccountType;
use App\Models\GeneralLedger\Account;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReceipt;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The collection desk: who may record a contact, what a promise needs, whether it was kept, and the worklist's buckets. */
class CollectionDeskTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 50, date: '2026-05-01');
    }

    private function desk(): CollectionDesk
    {
        return app(CollectionDesk::class);
    }

    private function receive(SalesInvoice $invoice, int $amount, string $date): void
    {
        $bank = Account::query()->ofType(AccountType::CashBank)->orderBy('no')->firstOrFail();
        $receipt = SalesReceipt::query()->create(['number' => 'CB-'.uniqid(), 'trans_date' => $date, 'customer_id' => $invoice->customer_id, 'branch_id' => $invoice->branch_id, 'bank_account_id' => $bank->id, 'payment_method' => 'cash', 'amount' => 0, 'created_by' => $this->owner->id]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => $invoice->id, 'amount' => $amount, 'discount' => 0]);
        $receipt->refreshTotal();
        $this->docs->created($receipt);
    }

    public function test_a_seat_records_a_contact_on_its_own_customers_invoice_and_a_promise_needs_a_day_ahead(): void
    {
        $invoice = $this->invoice(1, 100_000, date: today()->subDays(40)->toDateString());

        $this->actingAs($this->sales);
        $contact = $this->desk()->record($invoice, $this->sales, ['method' => 'phone', 'outcome' => 'promise', 'promise_date' => today()->addDays(3)->toDateString(), 'promise_amount' => 50_000, 'note' => 'after payday']);
        $this->assertSame($this->sales->id, $contact->user_id);
        $this->assertSame(50_000, $contact->promise_amount);
        $this->assertNull($this->desk()->promiseKept($invoice), 'the day is ahead');

        try {
            $this->desk()->record($invoice, $this->sales, ['method' => 'phone', 'outcome' => 'promise', 'promise_date' => today()->subDay()->toDateString()]);
            $this->fail('a promise for yesterday');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has passed', $e->getMessage());
        }
        try {
            $this->desk()->record($invoice, $this->sales, ['method' => 'phone', 'outcome' => 'promise']);
            $this->fail('a promise without a day');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs the day', $e->getMessage());
        }
        $other = $this->desk()->record($invoice, $this->sales, ['method' => 'visit', 'outcome' => 'asks_time', 'promise_date' => today()->addDay()->toDateString(), 'promise_amount' => 1]);
        $this->assertNull($other->promise_date, 'other outcomes carry no promise');

        $stranger = $this->member(CentralGroups::SALES, [$this->jakarta]);
        try {
            $this->desk()->record($invoice, $stranger, ['method' => 'phone', 'outcome' => 'unreachable']);
            $this->fail('not their customer');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not one of your customers', $e->getMessage());
        }
        try {
            $this->desk()->record($invoice, $this->inventory, ['method' => 'phone', 'outcome' => 'unreachable']);
            $this->fail('inventory sees no credit data');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Update right on Collections', $e->getMessage());
        }
        $this->assertCount(0, $this->desk()->chaseable($stranger)->get());
        $this->assertCount(1, $this->desk()->chaseable($this->finance)->get(), 'finance chases the branch');
    }

    public function test_a_promise_is_kept_by_receipts_after_the_contact_and_missed_when_the_day_passes(): void
    {
        $invoice = $this->invoice(1, 100_000, date: today()->subDays(40)->toDateString());
        $second = $this->invoice(1, 100_000, date: today()->subDays(40)->toDateString());
        $this->actingAs($this->finance);
        $this->desk()->record($invoice, $this->finance, ['method' => 'phone', 'outcome' => 'promise', 'promise_date' => today()->toDateString(), 'promise_amount' => 60_000, 'contacted_at' => today()->subDays(2)->toDateTimeString()]);
        $this->assertSame(CollectionDesk::DUE_TODAY, $this->desk()->bucket($invoice));

        $this->receive($invoice, 30_000, today()->toDateString());
        $this->assertNull($this->desk()->promiseKept($invoice->fresh()), 'half of it, the day not over');
        $this->receive($invoice, 30_000, today()->toDateString());
        $this->assertTrue($this->desk()->promiseKept($invoice->fresh()));
        $this->assertSame(CollectionDesk::REST, $this->desk()->bucket($invoice->fresh()));

        $this->desk()->record($second, $this->finance, ['method' => 'whatsapp', 'outcome' => 'promise', 'promise_date' => today()->toDateString(), 'contacted_at' => today()->subDays(3)->toDateTimeString()]);
        $this->travel(1)->days();
        $this->assertFalse($this->desk()->promiseKept($second->fresh()));
        $this->assertSame(CollectionDesk::MISSED, $this->desk()->bucket($second->fresh()));
        $this->travelBack();
    }

    public function test_the_worklist_buckets_and_the_screen(): void
    {
        $overdue = $this->invoice(1, 100_000, date: today()->subDays(60)->toDateString());
        $fresh = $this->invoice(1, 100_000, date: today()->toDateString());
        $this->assertNotNull($overdue->due_date);

        $list = $this->desk()->worklist($this->sales);
        $this->assertSame([$overdue->id], $list[CollectionDesk::UNCONTACTED]);
        $this->assertSame([$fresh->id], $list[CollectionDesk::REST]);

        $this->actingAs($this->sales);
        Livewire::test(Collections::class)->assertOk()
            ->assertSee($overdue->number)->assertSee(CollectionDesk::bucketLabel(CollectionDesk::UNCONTACTED))
            ->assertTableActionVisible('record', $overdue)
            ->callTableAction('record', $overdue, ['method' => 'phone', 'outcome' => 'promise', 'promise_date' => today()->addDays(2)->toDateString()])
            ->assertHasNoTableActionErrors();
        $this->assertSame(1, CollectionContact::query()->where('sales_invoice_id', $overdue->id)->count());

        $this->actingAs($this->inventory);
        $this->get('/admin/client/collections')->assertForbidden();
    }
}
