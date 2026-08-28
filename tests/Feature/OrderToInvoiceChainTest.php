<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\VirtualAccountProvisioner;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Uom\Unit;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Jobs\ReleaseStaleReservations;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentEntry;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\PriceTier;
use App\Models\Product;
use App\Models\User;
use App\Models\VirtualAccount;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The chain that makes this system usable:
 *
 *   draft → submitted → confirmed → awaiting_payment → paid → shipped
 *
 * Every piece existed before this; the links that were missing were order
 * entry and invoice issuance, so the back half — payment ledger, AR queues,
 * buyer portal — displayed data nothing in the app could produce.
 */
class OrderToInvoiceChainTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $sales;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.callback_token', 'test-token');
        // No secret key, so VAs are minted locally — see AppServiceProvider.
        config()->set('xendit.secret_key', '');

        $this->warehouse = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create();

        $tier = PriceTier::factory()->discount(250)->create();
        $this->company = Company::factory()->creditLimit(500_000_000)->create([
            'price_tier_id' => $tier->id,
            'payment_terms_days' => 30,
            'npwp' => '01.234.567.8-901.000',
            'nama_wajib_pajak' => 'CV Jaya Motor',
            'alamat_pajak' => 'Jl. Contoh No. 9, Bekasi',
        ]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        foreach ([['YH-1001', 400_000], ['OS-2002', 120_000]] as [$kode, $harga]) {
            Product::factory()->create(['kode' => $kode, 'qty_per_ctn' => 12]);
            PriceListItem::factory()->create([
                'version_id' => $version->id, 'kode' => $kode, 'harga' => $harga,
            ]);
            app(StockLedger::class)->record($kode, $this->warehouse->id, 500, MovementReason::Penerimaan);
        }
    }

    private function draftOrder(): Order
    {
        return Order::factory()->status(OrderStatus::Draft)->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
        ]);
    }

    private function machine(): OrderStateMachine
    {
        return app(OrderStateMachine::class);
    }

    // --- the whole chain ----------------------------------------------------

    public function test_an_order_runs_from_draft_to_shipped(): void
    {
        $order = $this->draftOrder();

        // Two cartons of twelve, plus fifty pieces.
        OrderLine::factory()->cartons(2, 12)->create([
            'order_id' => $order->id, 'sku' => 'YH-1001', 'urutan' => 1,
        ]);
        OrderLine::factory()->qty(50)->create([
            'order_id' => $order->id, 'sku' => 'OS-2002', 'urutan' => 2,
        ]);

        $machine = $this->machine();

        $machine->submit($order->refresh(), $this->sales);
        $this->assertSame(OrderStatus::Submitted, $order->refresh()->status);

        $machine->confirm($order, $this->approver());
        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);

        // Tier discount of 2.5% applied by resolvePrice, snapshotted per line.
        // 24 x 390.000 + 50 x 117.000 = 9.360.000 + 5.850.000
        $this->assertSame(15_210_000, $order->subtotal_rupiah);
        $this->assertSame(1100, (int) round($order->ppn_rupiah * 10_000 / $order->subtotal_rupiah));

        // Stock is fenced, not yet gone.
        $this->assertSame(500 - 24, app(StockLedger::class)->available('YH-1001', $this->warehouse->id));

        // --- the link that was missing -------------------------------------
        $machine->awaitPayment($order, $this->sales);
        $order->refresh();

        $invoice = $order->invoice;
        $this->assertNotNull($invoice, 'awaiting_payment must produce an invoice');
        $this->assertSame($order->total_rupiah, $invoice->total_rupiah);

        $va = VirtualAccount::query()->where('company_id', $this->company->id)->first();
        $this->assertNotNull($va, 'the buyer needs somewhere to pay into');

        // --- money in, through the only path to `paid` ----------------------
        $this->postJson('/webhooks/xendit', [
            'payment_id' => 'pay_chain_1',
            'amount' => $invoice->total_rupiah,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ], ['x-callback-token' => 'test-token'])->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->amountOutstanding());

        // --- out the door ---------------------------------------------------
        $machine->ship($order, User::factory()->warehouse()->create());
        $order->refresh();

        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertSame(500 - 24, app(StockLedger::class)->available('YH-1001', $this->warehouse->id));
        $this->assertSame([], app(StockLedger::class)->reconcile(), 'ledger must still reconcile');
    }

    // --- invoice issuance ---------------------------------------------------

    private function confirmedOrder(): Order
    {
        $order = $this->draftOrder();
        OrderLine::factory()->qty(10)->create(['order_id' => $order->id, 'sku' => 'YH-1001']);

        $this->machine()->submit($order->refresh(), $this->sales);
        $this->machine()->confirm($order, $this->approver());

        return $order->refresh();
    }

    public function test_the_invoice_totals_come_from_the_line_snapshots(): void
    {
        $order = $this->confirmedOrder();
        $this->machine()->awaitPayment($order, $this->sales);

        $invoice = $order->refresh()->invoice;
        $lines = $order->lines;

        $this->assertSame((int) $lines->sum('line_total_rupiah'), $invoice->subtotal_rupiah);
        $this->assertSame((int) $lines->sum('dpp_rupiah'), $invoice->dpp_rupiah);
        $this->assertSame((int) $lines->sum('ppn_rupiah'), $invoice->ppn_rupiah);
        $this->assertSame($invoice->subtotal_rupiah + $invoice->ppn_rupiah, $invoice->total_rupiah);
    }

    public function test_the_invoice_snapshots_the_tax_identity(): void
    {
        $order = $this->confirmedOrder();
        $this->machine()->awaitPayment($order, $this->sales);

        $invoice = $order->refresh()->invoice;

        $this->assertSame('01.234.567.8-901.000', $invoice->npwp);
        $this->assertSame('CV Jaya Motor', $invoice->nama_wajib_pajak);
        $this->assertSame('04', $invoice->kode_transaksi);

        // Changing the customer record later must not rewrite an issued faktur.
        $this->company->forceFill(['npwp' => '99.999.999.9-999.999'])->save();

        $this->assertSame('01.234.567.8-901.000', $invoice->refresh()->npwp);
    }

    public function test_the_due_date_follows_the_customers_payment_terms(): void
    {
        $order = $this->confirmedOrder();
        $this->machine()->awaitPayment($order, $this->sales);

        $invoice = $order->refresh()->invoice;

        $this->assertSame(
            $invoice->issued_on->copy()->addDays(30)->toDateString(),
            $invoice->due_date->toDateString(),
        );
    }

    public function test_billing_twice_does_not_issue_a_second_invoice(): void
    {
        $order = $this->confirmedOrder();
        $this->machine()->awaitPayment($order, $this->sales);

        $first = $order->refresh()->invoice;

        // Re-running the issuer directly, as a retried job would.
        $second = app(InvoiceIssuer::class)->issueFor($order);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_an_unconfirmed_order_cannot_be_invoiced(): void
    {
        $order = $this->draftOrder();
        OrderLine::factory()->qty(5)->create(['order_id' => $order->id, 'sku' => 'YH-1001']);

        $this->expectException(DomainException::class);
        app(InvoiceIssuer::class)->issueFor($order);
    }

    // --- numbering ----------------------------------------------------------

    public function test_document_numbers_are_sequential_and_scoped(): void
    {
        $numbers = app(DocumentNumberGenerator::class);
        $period = now()->format('Ym');
        $wilayah = $this->currentRegion()->kode;

        $this->assertSame("SO-{$wilayah}-{$period}-0001", $numbers->nextOrderNumber());
        $this->assertSame("SO-{$wilayah}-{$period}-0002", $numbers->nextOrderNumber());

        // Invoices count separately from orders.
        $this->assertSame("INV-{$wilayah}-{$period}-0001", $numbers->nextInvoiceNumber());
    }

    // --- virtual accounts ---------------------------------------------------

    public function test_a_company_keeps_one_fixed_va_per_bank(): void
    {
        $provisioner = app(VirtualAccountProvisioner::class);

        $first = $provisioner->ensureFor($this->company);
        $second = $provisioner->ensureFor($this->company);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VirtualAccount::where('company_id', $this->company->id)->count());
    }

    // --- the sweep must not bill an order it is about to cancel --------------

    /**
     * A stale `confirmed` order was previously pushed through awaiting_payment
     * to satisfy the state machine. Now that billing happens there, that would
     * invoice a customer for an order cancelled in the same breath.
     */
    public function test_the_stale_sweep_cancels_an_unbilled_order_without_invoicing_it(): void
    {
        $order = $this->confirmedOrder();
        $order->forceFill(['reservation_expires_at' => now()->subHour()])->save();

        (new ReleaseStaleReservations)->handle($this->machine());

        $order->refresh();

        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertNull($order->invoice, 'a cancelled order must not leave a phantom invoice');
        $this->assertSame(0, Invoice::count());

        // And the stock came back.
        $this->assertSame(500, app(StockLedger::class)->available('YH-1001', $this->warehouse->id));
    }

    public function test_the_stale_sweep_expires_an_order_that_was_billed_and_never_paid(): void
    {
        $order = $this->confirmedOrder();
        $this->machine()->awaitPayment($order, $this->sales);
        $order->refresh()->forceFill(['reservation_expires_at' => now()->subHour()])->save();

        (new ReleaseStaleReservations)->handle($this->machine());

        $order->refresh();

        $this->assertSame(OrderStatus::Expired, $order->status);
        // The invoice it was billed on still exists — the debt was real.
        $this->assertNotNull($order->invoice);
        $this->assertSame(500, app(StockLedger::class)->available('YH-1001', $this->warehouse->id));
    }

    // --- order entry derives what the ledger needs ---------------------------

    public function test_the_order_form_derives_base_quantity_from_the_ordered_unit(): void
    {
        $carton = OrderForm::withDerivedQuantities([
            'sku' => 'YH-1001',
            'ordered_unit' => Unit::Ctn->value,
            'ordered_qty' => 3,
        ]);

        $this->assertSame(36, $carton['qty_base']);
        $this->assertSame(12, $carton['qty_per_ctn_snapshot']);
        $this->assertSame('PCS', $carton['satuan_dasar_snapshot']);

        $pieces = OrderForm::withDerivedQuantities([
            'sku' => 'YH-1001',
            'ordered_unit' => Unit::Pcs->value,
            'ordered_qty' => 7,
        ]);

        $this->assertSame(7, $pieces['qty_base']);
    }

    public function test_a_paid_order_appears_on_the_buyers_ledger(): void
    {
        $order = $this->confirmedOrder();
        $this->machine()->awaitPayment($order, $this->sales);
        $invoice = $order->refresh()->invoice;
        $va = VirtualAccount::where('company_id', $this->company->id)->firstOrFail();

        $this->postJson('/webhooks/xendit', [
            'payment_id' => 'pay_chain_2',
            'amount' => $invoice->total_rupiah,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ], ['x-callback-token' => 'test-token'])->assertOk();

        $entry = PaymentEntry::sole();

        $this->assertSame($this->company->id, $entry->company_id);
        $this->assertSame($invoice->id, $entry->invoice_id);
        $this->assertSame($invoice->total_rupiah, $entry->amount_rupiah);
    }
}
