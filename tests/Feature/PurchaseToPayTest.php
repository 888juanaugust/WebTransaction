<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\PurchaseOrderFlow;
use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Purchasing\ThreeWayMatch;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\StockLedger;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierPaymentEntry;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Purchase to pay: PO → goods receipt → supplier bill → payment.
 *
 * The buy-side mirror of the order-to-cash chain, and it earns its keep at the
 * joins rather than in any one document. A purchase order alone is a wish, a
 * receipt alone cannot tell a short delivery from a complete one, and a bill
 * alone is whatever the supplier decided to charge. Agreement between the three
 * is the only thing that turns them into evidence.
 */
class PurchaseToPayTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-P2P-1';

    private Warehouse $warehouse;

    private Supplier $supplier;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create();
        $this->supplier = Supplier::factory()->create(['payment_terms_days' => 30]);
        $this->finance = User::factory()->role(Role::Finance)->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 12, 'satuan_dasar' => 'PCS']);
        Product::factory()->create(['kode' => 'YH-P2P-2', 'qty_per_ctn' => 6, 'satuan_dasar' => 'PCS']);
    }

    // --- helpers ------------------------------------------------------------

    /** @param  list<array{0: string, 1: int, 2: int}>  $lines  [sku, qty, unit cost] */
    private function purchaseOrder(array $lines, bool $send = true): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);

        foreach ($lines as $i => [$sku, $qty, $cost]) {
            PurchaseOrderLine::factory()->pieces($qty, $cost)->create([
                'purchase_order_id' => $po->id, 'sku' => $sku, 'urutan' => $i + 1,
            ]);
        }

        $po->refresh();

        return $send ? app(PurchaseOrderFlow::class)->send($po, $this->finance) : $po;
    }

    /** Receive some or all of a PO. @param  array<int, int>  $quantities  po line index => qty */
    private function receive(PurchaseOrder $po, array $quantities, ?int $unitCostOverride = null): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'warehouse_id' => $po->warehouse_id,
            'created_by' => $this->finance->id,
        ]);

        foreach ($quantities as $index => $qty) {
            $poLine = $po->lines[$index];
            $cost = $unitCostOverride ?? $poLine->unit_cost_rupiah;

            GoodsReceiptLine::factory()->pieces($qty, $cost)->create([
                'goods_receipt_id' => $receipt->id,
                'purchase_order_line_id' => $poLine->id,
                'sku' => $poLine->sku,
                'urutan' => $index + 1,
            ]);
        }

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    private function bill(GoodsReceipt $receipt, ?int $valueOverride = null): SupplierBill
    {
        $bill = SupplierBill::factory()->create([
            'supplier_id' => $receipt->supplier_id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'created_by' => $this->finance->id,
            'due_date' => now()->addDays($this->supplier->payment_terms_days)->toDateString(),
        ]);

        foreach ($receipt->lines as $i => $line) {
            SupplierBillLine::factory()->forReceiptLine($line, $valueOverride)->create([
                'supplier_bill_id' => $bill->id, 'urutan' => $i + 1,
            ]);
        }

        return app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);
    }

    // --- the whole chain ----------------------------------------------------

    public function test_a_purchase_runs_from_order_to_payment(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);

        $this->assertSame(PurchaseOrderStatus::Dikirim, $po->status);
        $this->assertSame(1_000_000, $po->total_value_rupiah);

        // Goods arrive: stock rises and the average cost forms.
        $receipt = $this->receive($po, [0 => 100]);

        $this->assertSame(100, app(StockLedger::class)->available(self::SKU, $this->warehouse->id));
        $this->assertSame(10_000, app(InventoryValuation::class)->unitCost(self::SKU));

        // The PO knows what turned up, and closes itself once nothing is left.
        $po->refresh()->load('lines');
        $this->assertSame(100, $po->lines[0]->qty_base_received);
        $this->assertSame(PurchaseOrderStatus::Selesai, $po->status);

        // The bill: PPN masukan computed per line.
        $bill = $this->bill($receipt);

        $this->assertSame(1_000_000, $bill->subtotal_rupiah);
        $this->assertSame(916_667, $bill->dpp_rupiah, 'DPP is 11/12 of the price');
        $this->assertSame(110_000, $bill->ppn_rupiah, 'PPN 12% of DPP — 11% effective');
        $this->assertSame(1_110_000, $bill->total_rupiah);
        $this->assertSame(SupplierBill::STATUS_OPEN, $bill->status);

        // And it is paid.
        app(SupplierLedger::class)->recordPayment(
            supplier: $this->supplier,
            amountRupiah: $bill->total_rupiah,
            actor: $this->finance,
            bill: $bill,
            referensi: 'TRF-0001',
        );

        $bill->refresh();
        $this->assertSame(0, $bill->amountOutstanding());
        $this->assertSame(SupplierBill::STATUS_PAID, $bill->status);
        $this->assertSame(0, app(SupplierLedger::class)->outstandingFor($this->supplier));
    }

    // --- the purchase order -------------------------------------------------

    public function test_a_draft_cannot_be_received_against_until_it_is_sent(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]], send: false);

        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertFalse($po->status->canReceive());
        $this->assertTrue($po->status->isEditable());
    }

    public function test_an_empty_order_cannot_be_sent(): void
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak punya baris');

        app(PurchaseOrderFlow::class)->send($po, $this->finance);
    }

    public function test_a_sent_order_cannot_be_sent_again(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 10, 1_000]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak bisa menjadi');

        app(PurchaseOrderFlow::class)->send($po, $this->finance);
    }

    /**
     * Cancelling an order stock was received against would leave the receipt
     * pointing at a document that says nothing was ever ordered.
     */
    public function test_an_order_that_has_received_goods_cannot_be_cancelled(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $this->receive($po, [0 => 40]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('harus diselesaikan, bukan dibatalkan');

        app(PurchaseOrderFlow::class)->cancel($po->refresh(), $this->finance, 'salah pesan');
    }

    public function test_an_untouched_order_can_be_cancelled_with_a_reason(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);

        app(PurchaseOrderFlow::class)->cancel($po, $this->finance, 'pemasok kehabisan stok');

        $this->assertSame(PurchaseOrderStatus::Dibatalkan, $po->refresh()->status);
        $this->assertSame('pemasok kehabisan stok', $po->alasan_batal);
    }

    // --- partial delivery ---------------------------------------------------

    public function test_a_partial_delivery_leaves_the_order_open_with_the_rest_outstanding(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);

        $this->receive($po, [0 => 40]);

        $po->refresh()->load('lines');

        $this->assertSame(40, $po->lines[0]->qty_base_received);
        $this->assertSame(60, $po->lines[0]->outstandingQty());
        $this->assertSame(PurchaseOrderStatus::Dikirim, $po->status, 'still open — more is expected');
        $this->assertFalse($po->isFullyReceived());
    }

    public function test_the_second_delivery_completes_and_closes_the_order(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);

        $this->receive($po, [0 => 40]);
        $this->receive($po->refresh(), [0 => 60]);

        $po->refresh()->load('lines');

        $this->assertSame(100, $po->lines[0]->qty_base_received);
        $this->assertSame(0, $po->lines[0]->outstandingQty());
        $this->assertSame(PurchaseOrderStatus::Selesai, $po->status);
        $this->assertSame(100, app(StockLedger::class)->available(self::SKU, $this->warehouse->id));
    }

    /**
     * Closing short is legitimate — a supplier discontinues a part mid-order.
     * What was still outstanding at that moment is the only evidence left.
     */
    public function test_an_order_can_be_closed_short_and_records_what_never_came(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $this->receive($po, [0 => 40]);

        app(PurchaseOrderFlow::class)->close($po->refresh(), $this->finance, 'barang discontinued');

        $this->assertSame(PurchaseOrderStatus::Selesai, $po->refresh()->status);

        $log = AuditLog::query()->where('action', 'purchase_order_closed')->latest('id')->first();
        $this->assertSame(['YH-P2P-1' => 60], $log->new_value['belum_diterima']);
    }

    /** The cached received quantity must be reconstructible from the receipts. */
    public function test_received_quantity_always_matches_the_receipts_behind_it(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000], ['YH-P2P-2', 50, 4_000]]);

        $this->receive($po, [0 => 40, 1 => 20]);
        $this->receive($po->refresh(), [0 => 25]);

        foreach ($po->refresh()->lines as $line) {
            $fromReceipts = (int) GoodsReceiptLine::query()
                ->where('purchase_order_line_id', $line->id)
                ->sum('qty_base');

            $this->assertSame($fromReceipts, $line->qty_base_received, "line {$line->sku}");
        }
    }

    // --- a receipt without a purchase order ---------------------------------

    /**
     * Stock sometimes simply turns up: an urgent counter purchase, or the
     * opening balance when the system goes live. Refusing to record that would
     * push people into recording it nowhere.
     */
    public function test_stock_can_still_be_received_with_no_purchase_order(): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
        ]);

        GoodsReceiptLine::factory()->pieces(30, 9_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        $this->assertNull($receipt->refresh()->purchase_order_id);
        $this->assertSame(30, app(StockLedger::class)->available(self::SKU, $this->warehouse->id));
    }

    // --- the bill -----------------------------------------------------------

    public function test_a_bills_total_is_fixed_at_posting_and_summed_from_its_lines(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000], ['YH-P2P-2', 50, 4_000]]);
        $receipt = $this->receive($po, [0 => 100, 1 => 50]);

        $bill = $this->bill($receipt);

        $lines = $bill->lines()->get();

        $this->assertSame((int) $lines->sum('line_total_rupiah'), $bill->subtotal_rupiah);
        $this->assertSame((int) $lines->sum('dpp_rupiah'), $bill->dpp_rupiah);
        $this->assertSame((int) $lines->sum('ppn_rupiah'), $bill->ppn_rupiah);
        $this->assertSame($bill->subtotal_rupiah + $bill->ppn_rupiah, $bill->total_rupiah);
    }

    public function test_posting_a_bill_twice_does_not_double_it(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $bill = $this->bill($this->receive($po, [0 => 100]));

        $before = $bill->total_rupiah;

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        $this->assertSame($before, $bill->refresh()->total_rupiah);
    }

    /** Input VAT is only creditable with the supplier's faktur pajak behind it. */
    public function test_input_vat_is_creditable_only_with_a_faktur_pajak_number(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $bill = $this->bill($this->receive($po, [0 => 100]));

        $this->assertGreaterThan(0, $bill->ppn_rupiah);
        $this->assertFalse($bill->isCreditableInput(), 'no NSFP yet');

        $bill->update(['nomor_faktur_pajak' => '0100012512345678']);

        $this->assertTrue($bill->refresh()->isCreditableInput());
    }

    public function test_an_empty_bill_is_refused(): void
    {
        $bill = SupplierBill::factory()->create(['supplier_id' => $this->supplier->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak punya baris');

        app(SupplierBillPoster::class)->post($bill, $this->finance);
    }

    // --- paying ------------------------------------------------------------

    public function test_a_part_payment_leaves_the_bill_open(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $bill = $this->bill($this->receive($po, [0 => 100]));

        app(SupplierLedger::class)->recordPayment(
            $this->supplier, 500_000, $this->finance, $bill
        );

        $bill->refresh();

        $this->assertSame(610_000, $bill->amountOutstanding());
        $this->assertSame(SupplierBill::STATUS_OPEN, $bill->status, 'part paid is not paid');
    }

    /**
     * The mirror of the customer-side rule: the ledger is append-only, and a
     * reversal can take a settled bill back to open because the money was never
     * really there.
     */
    public function test_reversing_a_payment_appends_and_reopens_the_bill(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $bill = $this->bill($this->receive($po, [0 => 100]));

        $entry = app(SupplierLedger::class)->recordPayment(
            $this->supplier, $bill->total_rupiah, $this->finance, $bill
        );

        $this->assertSame(SupplierBill::STATUS_PAID, $bill->refresh()->status);

        app(SupplierLedger::class)->reverse($entry, $this->finance, 'transfer gagal');

        $bill->refresh();

        $this->assertSame(2, $bill->paymentEntries()->count(), 'the original row survives');
        $this->assertSame($bill->total_rupiah, $bill->amountOutstanding());
        $this->assertSame(SupplierBill::STATUS_OPEN, $bill->status);
    }

    public function test_a_payment_cannot_be_recorded_against_another_suppliers_bill(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $bill = $this->bill($this->receive($po, [0 => 100]));

        $other = Supplier::factory()->create();

        $this->expectExceptionMessage('different supplier');

        app(SupplierLedger::class)->recordPayment($other, 100_000, $this->finance, $bill);
    }

    // --- three-way match ----------------------------------------------------

    public function test_a_clean_purchase_shows_no_variance(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $this->bill($this->receive($po, [0 => 100]));

        $this->assertSame([], app(ThreeWayMatch::class)->variancesFor($po->refresh()));
    }

    /** A part-delivered order is work in progress, not a variance to chase. */
    public function test_a_partial_delivery_is_not_reported_as_a_variance(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $this->bill($this->receive($po, [0 => 40]));

        $match = app(ThreeWayMatch::class)->forPurchaseOrder($po->refresh());

        $this->assertSame(60, $match[0]->quantityGap());
        $this->assertSame([], app(ThreeWayMatch::class)->variancesFor($po));
    }

    /**
     * The failure this control exists for: the supplier bills for goods that
     * never arrived. Usually a duplicated invoice line, not fraud — and it is
     * money out of the door either way.
     */
    public function test_being_billed_for_more_than_arrived_is_flagged(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $receipt = $this->receive($po, [0 => 40]);

        // The supplier bills the whole 100 against a 40-piece delivery.
        $bill = SupplierBill::factory()->create([
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $po->id,
        ]);
        SupplierBillLine::factory()->create([
            'supplier_bill_id' => $bill->id,
            'goods_receipt_line_id' => $receipt->lines[0]->id,
            'sku' => self::SKU,
            'qty_base' => 100,
            'line_total_rupiah' => 1_000_000,
        ]);
        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        $variances = app(ThreeWayMatch::class)->variancesFor($po->refresh());

        $this->assertCount(1, $variances);
        $this->assertTrue($variances[0]->isOverBilled());
        $this->assertSame(-60, $variances[0]->unbilledQty());
    }

    /**
     * A price that moved between the order and the invoice. The goods were
     * booked into stock at the receipt's cost; the bill disagrees.
     */
    public function test_a_price_that_moved_after_receiving_is_flagged(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $receipt = $this->receive($po, [0 => 100]);

        // Billed at 11.000 the piece, received at 10.000.
        $this->bill($receipt, valueOverride: 1_100_000);

        $variances = app(ThreeWayMatch::class)->variancesFor($po->refresh());

        $this->assertCount(1, $variances);
        $this->assertSame(100_000, $variances[0]->priceVariance());

        // And inventory is NOT silently adjusted — there is no purchase price
        // variance account to post the difference to.
        $this->assertSame(10_000, app(InventoryValuation::class)->unitCost(self::SKU));
    }

    public function test_receiving_more_than_was_ordered_is_flagged(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $this->receive($po, [0 => 120]);

        $variances = app(ThreeWayMatch::class)->variancesFor($po->refresh());

        $this->assertCount(1, $variances);
        $this->assertTrue($variances[0]->isOverReceived());

        // The match reports the gap signed, so an over-delivery reads as one.
        $this->assertSame(-20, $variances[0]->quantityGap());

        // The order line clamps instead: 20 pieces too many is not 20 pieces
        // still owed to us, and "outstanding" is what the buying list runs on.
        $po->refresh()->load('lines');
        $this->assertSame(0, $po->lines[0]->outstandingQty());
    }

    // --- who may do what ----------------------------------------------------

    /** @return list<array{0: Role, 1: bool}> */
    public static function purchasers(): array
    {
        return [
            'finance' => [Role::Finance, true],
            'owner' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'warehouse' => [Role::Warehouse, false],
        ];
    }

    #[DataProvider('purchasers')]
    public function test_only_purchasing_roles_may_send_a_purchase_order(Role $role, bool $allowed): void
    {
        $po = $this->purchaseOrder([[self::SKU, 10, 1_000]], send: false);
        $actor = User::factory()->role($role)->create();

        if ($allowed) {
            app(PurchaseOrderFlow::class)->send($po, $actor);
            $this->assertSame(PurchaseOrderStatus::Dikirim, $po->refresh()->status);

            return;
        }

        try {
            app(PurchaseOrderFlow::class)->send($po, $actor);
            $this->fail("{$role->value} was allowed to send a purchase order");
        } catch (DomainException $e) {
            $this->assertStringContainsString('tidak berhak', $e->getMessage());
        }

        $this->assertSame(PurchaseOrderStatus::Draft, $po->refresh()->status);
    }

    #[DataProvider('purchasers')]
    public function test_only_purchasing_roles_may_post_a_supplier_bill(Role $role, bool $allowed): void
    {
        $po = $this->purchaseOrder([[self::SKU, 10, 1_000]]);
        $receipt = $this->receive($po, [0 => 10]);

        $bill = SupplierBill::factory()->create(['supplier_id' => $this->supplier->id]);
        SupplierBillLine::factory()->forReceiptLine($receipt->lines[0])->create([
            'supplier_bill_id' => $bill->id,
        ]);

        $actor = User::factory()->role($role)->create();

        if ($allowed) {
            app(SupplierBillPoster::class)->post($bill->refresh(), $actor);
            $this->assertNotNull($bill->refresh()->posted_at);

            return;
        }

        try {
            app(SupplierBillPoster::class)->post($bill->refresh(), $actor);
            $this->fail("{$role->value} was allowed to post a supplier bill");
        } catch (DomainException $e) {
            $this->assertStringContainsString('tidak berhak', $e->getMessage());
        }

        $this->assertNull($bill->refresh()->posted_at);
    }

    /**
     * Sales may create a customer order but must not pay a supplier — the
     * mirror of the rule that keeps them away from confirming a payment in.
     */
    public function test_sales_cannot_pay_a_supplier(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 10, 1_000]]);
        $bill = $this->bill($this->receive($po, [0 => 10]));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak berhak');

        app(SupplierLedger::class)->recordPayment(
            $this->supplier, 1_000, User::factory()->sales()->create(), $bill
        );
    }

    /** Nothing may edit a posted bill's amount — the same rule invoices carry. */
    public function test_the_money_columns_on_a_bill_are_not_mass_assignable(): void
    {
        $bill = SupplierBill::factory()->create(['supplier_id' => $this->supplier->id]);

        foreach (['subtotal_rupiah', 'dpp_rupiah', 'ppn_rupiah', 'total_rupiah', 'status'] as $column) {
            $this->assertNotContains(
                $column,
                $bill->getFillable(),
                "{$column} must not be mass assignable on a supplier bill"
            );
        }
    }

    // --- accounts payable ---------------------------------------------------

    public function test_outstanding_payable_sums_bills_less_the_ledger(): void
    {
        $po = $this->purchaseOrder([[self::SKU, 100, 10_000]]);
        $bill = $this->bill($this->receive($po, [0 => 100]));

        $ledger = app(SupplierLedger::class);

        $this->assertSame(1_110_000, $ledger->outstandingFor($this->supplier));
        $this->assertSame(1_110_000, $ledger->totalPayable());

        $ledger->recordPayment($this->supplier, 400_000, $this->finance, $bill);

        $this->assertSame(710_000, $ledger->outstandingFor($this->supplier));
        $this->assertSame(710_000, $ledger->totalPayable());
    }

    public function test_a_payment_can_be_recorded_before_it_is_matched_to_a_bill(): void
    {
        $entry = app(SupplierLedger::class)->recordPayment(
            $this->supplier, 250_000, $this->finance, referensi: 'TRF-9'
        );

        $this->assertNull($entry->supplier_bill_id);
        $this->assertSame(1, SupplierPaymentEntry::query()->whereNull('supplier_bill_id')->count());

        // It still reduces what we owe the supplier overall.
        $this->assertSame(-250_000, app(SupplierLedger::class)->outstandingFor($this->supplier));
    }
}
