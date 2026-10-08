<?php

namespace Tests\Feature\Domain;

use App\Domain\Posting\DocumentRepository;
use App\Domain\Reports\Period;
use App\Domain\Reports\TradeReports;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesReceipt;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/** A receipt line settles no more than is open, never a negative amount on an ordinary invoice, and only its own customer's documents. */
class SettlementLimitTest extends TestCase
{
    private Customer $customer;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->customer = $this->sampleCustomer();
        $this->invoice = $this->invoice($this->customer, 'INV-1');
    }

    private function invoice(Customer $customer, string $number): SalesInvoice
    {
        $service = $this->sampleItem(['number' => 'SVC-'.$number, 'name' => 'Service', 'item_type' => 'service']);
        $invoice = SalesInvoice::query()->create(['number' => $number, 'trans_date' => '2026-11-10', 'customer_id' => $customer->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $service->id, 'quantity' => 1, 'unit_id' => $service->unit1_id, 'base_quantity' => 1, 'unit_price' => 10_000_000, 'warehouse_id' => Warehouse::default()->id]);
        $invoice->refreshTotal();
        app(DocumentRepository::class)->created($invoice);

        return $invoice->fresh();
    }

    private function receive(string $number, int $amount, int $discount = 0, ?Customer $customer = null, ?SalesInvoice $invoice = null): SalesReceipt
    {
        $receipt = SalesReceipt::query()->create(['number' => $number, 'trans_date' => '2026-11-20', 'customer_id' => ($customer ?? $this->customer)->id,
            'bank_account_id' => Account::query()->where('no', '1102')->value('id'), 'created_by' => auth()->id()]);
        $receipt->lines()->create(['sort' => 0, 'receivable_type' => 'sales_invoice', 'receivable_id' => ($invoice ?? $this->invoice)->id, 'amount' => $amount, 'discount' => $discount]);
        $receipt->refreshTotal();
        app(DocumentRepository::class)->created($receipt);

        return $receipt;
    }

    private function refused(callable $make, string $message): void
    {
        try {
            $make();
            $this->fail("refused: {$message}");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_no_line_settles_more_than_is_open(): void
    {
        $this->refused(fn () => $this->receive('CB-1', 15_000_000), 'cannot settle more');
        $this->refused(fn () => $this->receive('CB-2', 9_900_000, 200_000), 'cannot settle more');
        $this->assertSame('unpaid', $this->invoice->fresh()->payment_status, 'nothing was posted');

        $this->receive('CB-3', 10_000_000);
        $this->assertSame('paid', $this->invoice->fresh()->payment_status);
        $this->refused(fn () => $this->receive('CB-4', 10_000_000), 'cannot settle more');
    }

    public function test_no_negative_amount_on_an_invoice_and_no_other_customers_invoice(): void
    {
        $this->refused(fn () => $this->receive('CB-1', -1_000_000), 'cannot be negative');

        $other = $this->sampleCustomer(['number' => 'C-00002', 'name' => 'Other Co']);
        $theirs = $this->invoice($other, 'INV-2');
        $this->refused(fn () => $this->receive('CB-2', 1_000_000, invoice: $theirs), 'belongs to another customer');
        $this->assertSame('unpaid', $theirs->fresh()->payment_status);
    }

    public function test_aging_as_at_a_past_date_counts_what_was_open_then(): void
    {
        $this->receive('CB-1', 4_000_000); // on 20 November

        $then = collect(TradeReports::receivableAging(new Period('2026-11-01', '2026-11-15')))->firstWhere('id', $this->customer->id);
        $this->assertSame(10_000_000, $then['total'], 'on the 15th nothing had been paid');
        $now = collect(TradeReports::receivableAging(new Period('2026-11-01', '2026-11-20')))->firstWhere('id', $this->customer->id);
        $this->assertSame(6_000_000, $now['total']);

        $this->receive('CB-2', 6_000_000);
        $this->assertSame('paid', $this->invoice->fresh()->payment_status);
        $then = collect(TradeReports::receivableAging(new Period('2026-11-01', '2026-11-15')))->firstWhere('id', $this->customer->id);
        $this->assertSame(10_000_000, $then['total'], 'paid today, still owed as at the 15th');
    }
}
