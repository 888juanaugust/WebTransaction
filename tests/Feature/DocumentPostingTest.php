<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\DocumentPoster;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Accounting\TrialBalance;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\PurchaseOrderFlow;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Stock\InventoryValuation;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every document, and what it does to the books.
 *
 * The trial balance proves the ledger is internally consistent; it cannot
 * prove the postings were right, because a rule that credits the wrong account
 * balances perfectly and is still wrong. What catches that is the control
 * account — Piutang Usaha has to equal the open customer invoices, Persediaan
 * has to equal the inventory valuation — so most of these tests end by
 * checking the books against the subledger they were built from rather than
 * against a number I typed.
 */
class DocumentPostingTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-GL-1';

    private Warehouse $warehouse;

    private User $sales;

    private User $finance;

    private Company $company;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.secret_key', '');

        $this->warehouse = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->supplier = Supplier::factory()->create(['payment_terms_days' => 30]);

        $this->company = Company::factory()->creditLimit(500_000_000)->create([
            'payment_terms_days' => 30,
        ]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);
    }

    // ------------------------------------------------------------- buy side

    public function test_a_goods_receipt_puts_the_value_into_stock_and_accrues_what_we_owe(): void
    {
        $this->receiveStock(100, 60_000);

        // Not yet Utang Usaha: the supplier has not billed, so we do not owe
        // anybody a specific amount.
        $this->assertSame(6_000_000, $this->balance(AccountCode::PERSEDIAAN));
        $this->assertSame(6_000_000, $this->balance(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(0, $this->balance(AccountCode::UTANG_USAHA));
    }

    public function test_the_ledgers_inventory_equals_the_inventory_valuation(): void
    {
        $this->receiveStock(100, 60_000);
        $this->receiveStock(50, 66_000);

        $this->assertSame(
            app(InventoryValuation::class)->totalValue(),
            $this->balance(AccountCode::PERSEDIAAN),
        );
    }

    public function test_a_supplier_bill_clears_the_accrual_and_creates_the_debt(): void
    {
        $receipt = $this->receiveStock(100, 60_000);
        $bill = $this->billFor($receipt);

        // 6,000,000 net; PPN 11/12 × 12% = 660,000; 6,660,000 owed.
        $this->assertSame(6_660_000, (int) $bill->total_rupiah);

        $this->assertSame(0, $this->balance(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(6_660_000, $this->balance(AccountCode::UTANG_USAHA));
        $this->assertSame(660_000, $this->balance(AccountCode::PPN_MASUKAN));
        $this->assertSame(0, $this->balance(AccountCode::SELISIH_HARGA_PEMBELIAN));

        // Inventory is untouched by billing. The goods were valued on arrival.
        $this->assertSame(6_000_000, $this->balance(AccountCode::PERSEDIAAN));
    }

    public function test_a_supplier_billing_more_than_the_goods_arrived_at_hits_the_variance_account(): void
    {
        $receipt = $this->receiveStock(100, 60_000);

        // Billed at 63,000 a piece rather than the 60,000 they were received at.
        $this->billFor($receipt, 6_300_000);

        $this->assertSame(300_000, $this->balance(AccountCode::SELISIH_HARGA_PEMBELIAN));

        // The accrual still clears in full, and stock keeps its own cost.
        $this->assertSame(0, $this->balance(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(6_000_000, $this->balance(AccountCode::PERSEDIAAN));
        $this->assertSame(app(InventoryValuation::class)->totalValue(), $this->balance(AccountCode::PERSEDIAAN));
    }

    public function test_a_supplier_billing_less_credits_the_variance_account(): void
    {
        $receipt = $this->receiveStock(100, 60_000);
        $this->billFor($receipt, 5_700_000);

        $this->assertSame(-300_000, $this->balance(AccountCode::SELISIH_HARGA_PEMBELIAN));
        $this->assertSame(0, $this->balance(AccountCode::UTANG_BELUM_DITAGIH));
    }

    public function test_input_vat_without_a_faktur_pajak_is_a_cost_not_an_asset(): void
    {
        $receipt = $this->receiveStock(100, 60_000);
        $this->billFor($receipt, fakturPajak: null);

        // Not creditable against what we collect, so it is not an asset.
        $this->assertSame(0, $this->balance(AccountCode::PPN_MASUKAN));
        $this->assertSame(660_000, $this->balance(AccountCode::BEBAN_OPERASIONAL));
    }

    public function test_paying_a_supplier_clears_the_payable(): void
    {
        $receipt = $this->receiveStock(100, 60_000);
        $bill = $this->billFor($receipt);

        app(SupplierLedger::class)->recordPayment($this->supplier, 6_660_000, $this->finance, $bill);

        $this->assertSame(0, $this->balance(AccountCode::UTANG_USAHA));
        $this->assertSame(-6_660_000, $this->balance(AccountCode::BANK));
    }

    public function test_reversing_a_supplier_payment_puts_the_debt_back(): void
    {
        $receipt = $this->receiveStock(100, 60_000);
        $bill = $this->billFor($receipt);
        $ledger = app(SupplierLedger::class);

        $entry = $ledger->recordPayment($this->supplier, 6_660_000, $this->finance, $bill);
        $ledger->reverse($entry, $this->finance, 'Transfer gagal');

        $this->assertSame(6_660_000, $this->balance(AccountCode::UTANG_USAHA));
        $this->assertSame(0, $this->balance(AccountCode::BANK));

        // Appended, not edited: two entries, and both still on the books.
        $this->assertSame(2, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_PEMBAYARAN_PEMASOK)->count());
    }

    // ------------------------------------------------------------ sell side

    public function test_an_invoice_creates_the_receivable_and_the_tax_we_owe(): void
    {
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $invoice = $order->invoice;

        $this->assertSame(
            (int) $invoice->total_rupiah,
            $this->balance(AccountCode::PIUTANG_USAHA),
        );
        $this->assertSame((int) $invoice->subtotal_rupiah, $this->balance(AccountCode::PENJUALAN));
        $this->assertSame((int) $invoice->ppn_rupiah, $this->balance(AccountCode::PPN_KELUARAN));
    }

    public function test_revenue_is_booked_before_the_cost_because_that_is_when_the_invoice_is_issued(): void
    {
        /*
         * Worth stating outright rather than discovering later: the order flow
         * invoices at awaiting_payment, which is before the goods ship. So the
         * sale appears on the profit and loss while its cost is still sitting
         * in inventory, and if the two straddle a month end the margin for
         * that month is wrong in both directions.
         *
         * That is a policy question for the accountant, not a bug in the
         * posting rule — and it is recorded in docs/MAP.md.
         */
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);

        $this->assertGreaterThan(0, $this->balance(AccountCode::PENJUALAN));
        $this->assertSame(0, $this->balance(AccountCode::HARGA_POKOK_PENJUALAN));
        $this->assertSame(6_000_000, $this->balance(AccountCode::PERSEDIAAN));

        $this->shipAndComplete($order);

        $this->assertSame(3_000_000, $this->balance(AccountCode::HARGA_POKOK_PENJUALAN));
        $this->assertSame(3_000_000, $this->balance(AccountCode::PERSEDIAAN));
    }

    public function test_cost_of_sales_is_the_cost_frozen_at_the_shipment_not_todays_average(): void
    {
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $this->shipAndComplete($order);

        $this->assertSame(3_000_000, $this->balance(AccountCode::HARGA_POKOK_PENJUALAN));

        // Buying dearer today must not restate what last week's sale cost.
        $this->receiveStock(100, 90_000);

        $this->assertSame(3_000_000, $this->balance(AccountCode::HARGA_POKOK_PENJUALAN));
        $this->assertSame(app(InventoryValuation::class)->totalValue(), $this->balance(AccountCode::PERSEDIAAN));
    }

    public function test_a_customer_payment_clears_the_receivable(): void
    {
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $invoice = $order->invoice;

        app(PaymentLedger::class)->recordManualPayment(
            $this->company, (int) $invoice->total_rupiah, $this->finance, $invoice
        );

        $this->assertSame(0, $this->balance(AccountCode::PIUTANG_USAHA));
        $this->assertSame((int) $invoice->total_rupiah, $this->balance(AccountCode::BANK));
    }

    public function test_a_part_payment_leaves_the_rest_receivable(): void
    {
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $invoice = $order->invoice;

        app(PaymentLedger::class)->recordManualPayment(
            $this->company, 2_000_000, $this->finance, $invoice
        );

        $this->assertSame((int) $invoice->total_rupiah - 2_000_000, $this->balance(AccountCode::PIUTANG_USAHA));
        $this->assertSame(2_000_000, $this->balance(AccountCode::BANK));
    }

    public function test_reversing_a_customer_payment_puts_the_receivable_back(): void
    {
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $invoice = $order->invoice;
        $payments = app(PaymentLedger::class);

        $entry = $payments->recordManualPayment(
            $this->company, (int) $invoice->total_rupiah, $this->finance, $invoice
        );
        $payments->reverse($entry, $this->finance, 'Transfer ditarik kembali');

        $this->assertSame((int) $invoice->total_rupiah, $this->balance(AccountCode::PIUTANG_USAHA));
        $this->assertSame(0, $this->balance(AccountCode::BANK));
    }

    // ------------------------------------------------------------ the whole

    public function test_a_full_trading_cycle_leaves_the_books_reconciled(): void
    {
        // Buy 100 at 60,000, sell 50 at list, pay for both sides.
        $receipt = $this->receiveStock(100, 60_000);
        $bill = $this->billFor($receipt);
        app(SupplierLedger::class)->recordPayment($this->supplier, 6_660_000, $this->finance, $bill);

        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $this->shipAndComplete($order);

        app(PaymentLedger::class)->recordManualPayment(
            $this->company, (int) $order->invoice->total_rupiah, $this->finance, $order->invoice
        );

        $reconciliation = app(LedgerReconciliation::class);

        $this->assertSame([], array_map(
            fn ($c) => "{$c->nama}: buku {$c->buku} vs subledger {$c->subledger}",
            $reconciliation->discrepancies(),
        ));
        $this->assertTrue($reconciliation->isClean());
        $this->assertTrue(TrialBalance::asOf()->isBalanced());
    }

    public function test_the_cycle_turns_a_profit_the_size_of_the_margin(): void
    {
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $this->shipAndComplete($order);

        $tb = TrialBalance::asOf();

        // 50 pieces: sold at 100,000, cost 60,000.
        $this->assertSame(5_000_000, $tb->totalOfType(AccountType::Pendapatan));
        $this->assertSame(3_000_000, $tb->totalOfType(AccountType::Beban));
    }

    public function test_a_variance_shows_up_as_a_discrepancy_nowhere_and_an_expense_somewhere(): void
    {
        $receipt = $this->receiveStock(100, 60_000);
        $this->billFor($receipt, 6_300_000);

        // The control accounts still tie — the variance is an expense, not a
        // hole. This is the check that would catch the rule being wrong.
        $this->assertTrue(app(LedgerReconciliation::class)->isClean());
        $this->assertSame(300_000, TrialBalance::asOf()->totalOfType(AccountType::Beban));
    }

    public function test_a_bill_that_arrives_before_the_goods_pushes_the_accrual_contra(): void
    {
        // No receipt behind this line, so there is nothing to clear: the whole
        // amount accrues against Utang Belum Ditagih, which goes negative
        // until the delivery turns up and puts it back. That is what the
        // account is for, and it is the case the reconciliation has to know
        // about or it reports a drift that is not there.
        $bill = SupplierBill::factory()->create([
            'supplier_id' => $this->supplier->id,
            'nomor_faktur_pajak' => '010.000-26.00000009',
            'created_by' => $this->finance->id,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        SupplierBillLine::factory()->create([
            'supplier_bill_id' => $bill->id,
            'goods_receipt_line_id' => null,
            'sku' => self::SKU,
            'urutan' => 1,
            'qty_base' => 100,
            'unit_cost_rupiah' => 60_000,
            'line_total_rupiah' => 6_000_000,
        ]);

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        $this->assertSame(-6_000_000, $this->balance(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(0, $this->balance(AccountCode::SELISIH_HARGA_PEMBELIAN));
        $this->assertSame(0, $this->balance(AccountCode::PERSEDIAAN));
        $this->assertTrue(app(LedgerReconciliation::class)->isClean());
    }

    public function test_the_reconciliation_notices_when_the_books_drift(): void
    {
        /*
         * The check has to be able to fail, or it is decoration. A manual
         * journal into a control account is exactly the sort of well-meant
         * correction that silently breaks the tie to the subledger, so that is
         * what this does.
         */
        $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);

        $reconciliation = app(LedgerReconciliation::class);
        $this->assertTrue($reconciliation->isClean());

        app(Ledger::class)->postManual(
            JournalDraft::manual('Koreksi tanpa dokumen')
                ->debit(AccountCode::PIUTANG_USAHA, 1_000_000)
                ->kredit(AccountCode::PENJUALAN, 1_000_000),
            $this->finance,
        );

        $this->assertFalse($reconciliation->isClean());

        $drift = $reconciliation->discrepancies();
        $this->assertCount(1, $drift);
        $this->assertSame(AccountCode::PIUTANG_USAHA, $drift[0]->kode);
        $this->assertSame(1_000_000, $drift[0]->selisih());

        // Still balanced. Balanced and wrong is the whole point of the check.
        $this->assertTrue(TrialBalance::asOf()->isBalanced());
    }

    public function test_every_posting_is_traceable_back_to_the_document_that_caused_it(): void
    {
        $receipt = $this->receiveStock(100, 60_000);
        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $this->shipAndComplete($order);

        $ledger = app(Ledger::class);

        $this->assertCount(1, $ledger->entriesForDocument($receipt));
        $this->assertCount(1, $ledger->entriesForDocument($order->invoice));
        $this->assertCount(1, $ledger->entriesForDocument($order));

        JournalEntry::query()->get()->each(function (JournalEntry $entry) {
            $this->assertNotNull($entry->source_type, "{$entry->nomor} has no document behind it.");
            $this->assertNotNull($entry->source, "{$entry->nomor} points at a document that is gone.");
        });
    }

    public function test_posting_a_document_twice_does_not_double_the_books(): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces(100, 60_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $poster = app(GoodsReceiptPoster::class);
        $poster->post($receipt->refresh(), $this->finance);

        // The receipt itself refuses a second posting, so drive the ledger
        // directly: this is the queue-runs-twice case, not the double-click.
        app(DocumentPoster::class)
            ->goodsReceived($receipt->refresh(), $this->finance);

        $this->assertSame(6_000_000, $this->balance(AccountCode::PERSEDIAAN));
        $this->assertSame(1, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_PENERIMAAN_BARANG)->count());
    }

    public function test_the_books_balance_after_every_kind_of_document(): void
    {
        $ledger = app(Ledger::class);

        $receipt = $this->receiveStock(100, 60_000);
        $this->assertTrue($ledger->isBalanced(), 'after goods receipt');

        $bill = $this->billFor($receipt);
        $this->assertTrue($ledger->isBalanced(), 'after supplier bill');

        app(SupplierLedger::class)->recordPayment($this->supplier, 1_000_000, $this->finance, $bill);
        $this->assertTrue($ledger->isBalanced(), 'after supplier payment');

        $order = $this->orderThrough(OrderStatus::AwaitingPayment, 50);
        $this->assertTrue($ledger->isBalanced(), 'after invoice');

        $this->shipAndComplete($order);
        $this->assertTrue($ledger->isBalanced(), 'after shipment');

        app(PaymentLedger::class)->recordManualPayment($this->company, 1_000_000, $this->finance, $order->invoice);
        $this->assertTrue($ledger->isBalanced(), 'after customer payment');
    }

    // --- helpers ------------------------------------------------------------

    private function balance(string $kode): int
    {
        return app(Ledger::class)->balanceOf($kode);
    }

    /** Receive stock against a purchase order, so the bill has a receipt line to clear. */
    private function receiveStock(int $qty, int $unitCost): GoodsReceipt
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);
        PurchaseOrderLine::factory()->pieces($qty, $unitCost)->create([
            'purchase_order_id' => $po->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);
        $po = app(PurchaseOrderFlow::class)->send($po->refresh(), $this->finance);

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $po->lines[0]->id,
            'sku' => self::SKU,
            'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    private function billFor(
        GoodsReceipt $receipt,
        ?int $valueOverride = null,
        ?string $fakturPajak = '010.000-26.00000001',
    ): SupplierBill {
        $bill = SupplierBill::factory()->create([
            'supplier_id' => $receipt->supplier_id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'nomor_faktur_pajak' => $fakturPajak,
            'created_by' => $this->finance->id,
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        foreach ($receipt->lines as $i => $line) {
            SupplierBillLine::factory()->forReceiptLine($line, $valueOverride)->create([
                'supplier_bill_id' => $bill->id, 'urutan' => $i + 1,
            ]);
        }

        return app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);
    }

    /** An order driven to a given state, for `$qty` pieces. */
    private function orderThrough(OrderStatus $target, int $qty): Order
    {
        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->sales);

        if ($target !== OrderStatus::Confirmed) {
            $machine->awaitPayment($order->refresh(), $this->sales);
        }

        return $order->refresh()->load('invoice');
    }

    private function shipAndComplete(Order $order): void
    {
        $machine = app(OrderStateMachine::class);
        $warehouse = User::factory()->role(Role::Warehouse)->create();

        $machine->markPaid($order->refresh(), ['sumber' => 'test']);
        $machine->ship($order->refresh(), $warehouse);
    }
}
