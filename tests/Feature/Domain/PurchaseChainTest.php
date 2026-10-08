<?php

namespace Tests\Feature\Domain;

use App\Domain\Inventory\StockQuery;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\Exceptions\DocumentLockedException;
use App\Domain\Settlement\SettlementService;
use App\Models\Company\PaymentTerm;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\PurchaseDownPayment;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\PurchaseReturn;
use App\Models\Purchasing\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchaseChainTest extends TestCase
{
    private Vendor $vendor;

    private Item $item;

    private Warehouse $warehouse;

    private TaxCode $vat;

    private DocumentRepository $docs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->vendor = $this->sampleVendor();
        $this->item = Item::query()->create(['number' => 'ITM-00001', 'name' => 'Widget', 'unit1_id' => Unit::query()->where('name', 'PCS')->value('id')]);
        $this->warehouse = Warehouse::default();
        $this->vat = TaxCode::default();
        $this->docs = app(DocumentRepository::class);
    }

    private function balance(string $no): int
    {
        return AccountBalances::asOf()[Account::query()->where('no', $no)->value('id')] ?? 0;
    }

    private function order(int $qty, int $price, string $date = '2026-11-01'): PurchaseOrder
    {
        $po = PurchaseOrder::query()->create(['number' => 'PO-1', 'trans_date' => $date, 'vendor_id' => $this->vendor->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $po->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_price' => $price, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $this->warehouse->id]);
        $po->refreshTotal();
        $this->docs->created($po);

        return $po->fresh();
    }

    private function receive(PurchaseOrder $po, int $qty, string $date = '2026-11-03'): GoodsReceipt
    {
        $line = $po->lines()->first();
        $gr = GoodsReceipt::query()->create(['number' => 'GR-'.uniqid(), 'trans_date' => $date, 'vendor_id' => $this->vendor->id, 'taxable' => $po->taxable, 'inclusive_tax' => $po->inclusive_tax, 'created_by' => auth()->id()]);
        $gr->lines()->create(['sort' => 0, 'item_id' => $line->item_id, 'quantity' => $qty, 'unit_id' => $line->unit_id, 'base_quantity' => $qty, 'unit_price' => $line->unit_price, 'tax_code_id' => $line->tax_code_id, 'warehouse_id' => $line->warehouse_id, 'source_line_type' => 'purchase_order_line', 'source_line_id' => $line->id]);
        $gr->refreshTotal();
        $this->docs->created($gr);

        return $gr->fresh();
    }

    public function test_order_receipt_invoice_and_payment_keep_stock_books_and_statuses_in_step(): void
    {
        $po = $this->order(10, 100_000);
        $this->assertSame(1_000_000, $po->subtotal);
        $this->assertSame(110_000, $po->tax_total, '12 % on 11/12 of the price');
        $this->assertSame(1_110_000, $po->total);
        $this->assertSame('pending', $po->status);

        $gr = $this->receive($po, 6);
        $this->assertSame('6.0000', StockQuery::onHand($this->item->id));
        $this->assertSame(600_000, $this->balance('1300'), 'inventory at the order price');
        $this->assertSame(600_000, $this->balance('2110'), 'owed as goods received, not invoiced');
        $this->assertSame('6.0000', $po->lines()->first()->fresh()->processed_quantity);
        $this->assertSame('partial', $po->fresh()->status);

        $this->receive($po, 4, '2026-11-04');
        $this->assertSame('processed', $po->fresh()->status);

        // The invoice pulls the first receipt at a higher price, with freight allocated to cost.
        $grLine = $gr->lines()->first();
        $invoice = PurchaseInvoice::query()->create(['number' => 'BILL-1', 'bill_number' => 'SP/2026/118', 'trans_date' => '2026-11-05', 'vendor_id' => $this->vendor->id, 'taxable' => true, 'inclusive_tax' => false, 'payment_term_id' => PaymentTerm::default()->id, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 6, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 6, 'unit_price' => 105_000, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $this->warehouse->id, 'source_line_type' => 'goods_receipt_line', 'source_line_id' => $grLine->id]);
        $invoice->charges()->create(['sort' => 0, 'account_id' => Account::query()->where('no', '6300')->value('id'), 'amount' => 60_000, 'description' => 'Freight', 'allocate_to_cost' => true]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);
        $invoice->refresh();

        $this->assertSame(630_000, $invoice->subtotal);
        $this->assertSame(69_300, $invoice->tax_total);
        $this->assertSame(759_300, $invoice->total);
        $this->assertSame('2026-12-05', $invoice->due_date->toDateString(), 'net 30');
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame('processed', $gr->fresh()->status, 'the receipt is fully invoiced');
        $this->assertSame(400_000, $this->balance('2110'), 'only the second, uninvoiced receipt is still owed as goods received, not invoiced');
        $this->assertSame(759_300, $this->balance('2100'), 'the payable is the invoice total');
        $this->assertSame(69_300, $this->balance('1400'), 'VAT in');
        $this->assertSame(400_000 + 630_000 + 60_000, $this->balance('1300'), 'inventory carries the price difference and the freight');
        $cache = ItemCost::query()->where('item_id', $this->item->id)->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertSame(1_090_000, $cache->total_value);
        $this->assertSame('109000.0000', $cache->avg_cost);

        // Payment with a discount taken.
        $payment = PurchasePayment::query()->create(['number' => 'CB-1', 'trans_date' => '2026-11-10', 'vendor_id' => $this->vendor->id, 'bank_account_id' => Account::query()->where('no', '1102')->value('id'), 'payment_method' => 'bank_transfer', 'created_by' => auth()->id()]);
        $payment->lines()->create(['sort' => 0, 'payable_type' => 'purchase_invoice', 'payable_id' => $invoice->id, 'amount' => 750_000, 'discount' => 9_300]);
        $payment->refreshTotal();
        $this->docs->created($payment);

        $invoice->refresh();
        $this->assertSame(759_300, $invoice->paid_amount);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(0, $this->balance('2100'));
        $this->assertSame(-750_000, $this->balance('1102'));
        $this->assertSame(-9_300, $this->balance('5300'), 'the discount taken, a credit on the cost side');
        $this->assertSame(0, app(SettlementService::class)->balance($invoice));

        // Blockers: the receipt is referenced, the invoice is settled.
        try {
            $this->docs->delete($gr->fresh());
            $this->fail('a receipt the invoice was made from must not be deleted');
        } catch (DocumentLockedException $e) {
            $this->assertStringContainsString('another document', $e->getMessage());
        }
        try {
            $this->docs->beforeUpdate($invoice->fresh());
            $this->fail('a paid invoice must not change');
        } catch (DocumentLockedException $e) {
            $this->assertStringContainsString('payments', $e->getMessage());
        }

        // Undoing the payment reopens the invoice.
        $this->docs->delete($payment->fresh());
        $this->assertSame('unpaid', $invoice->fresh()->payment_status);
        $this->assertSame(759_300, $this->balance('2100'));
    }

    public function test_a_down_payment_is_a_payable_and_is_deducted_on_the_invoice(): void
    {
        $dp = PurchaseDownPayment::query()->create(['number' => 'BILL-DP-1', 'trans_date' => '2026-11-01', 'vendor_id' => $this->vendor->id, 'amount' => 500_000, 'taxable' => true, 'inclusive_tax' => false, 'tax_code_id' => $this->vat->id, 'created_by' => auth()->id()]);
        $dp->refreshTotal();
        $this->docs->created($dp);
        $dp->refresh();
        $this->assertSame(555_000, $dp->total);
        $this->assertSame(500_000, $this->balance('1410'));
        $this->assertSame(555_000, $this->balance('2100'));

        $invoice = PurchaseInvoice::query()->create(['number' => 'BILL-2', 'trans_date' => '2026-11-06', 'vendor_id' => $this->vendor->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 10, 'unit_price' => 100_000, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $this->warehouse->id]);
        $invoice->downPayments()->create(['purchase_down_payment_id' => $dp->id, 'amount' => 555_000]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);
        $invoice->refresh();

        $this->assertSame(1_110_000, $invoice->total);
        $this->assertSame(555_000, $invoice->down_payment_total, 'deducted gross, VAT included');
        $this->assertSame(555_000, $invoice->balance());
        $this->assertSame(0, $this->balance('1410'), 'the down payment account is cleared');
        $this->assertSame(110_000, $this->balance('1400'), 'VAT in is not counted twice');
        $this->assertSame(555_000 + 555_000, $this->balance('2100'));
        $this->assertSame('10.0000', StockQuery::onHand($this->item->id), 'a direct invoice brings the goods in');
        $this->assertSame('processed', $dp->fresh()->status);
    }

    public function test_a_return_against_a_receipt_takes_back_its_net_value_and_no_vat(): void
    {
        $gr = $this->receive($this->order(10, 100_000), 10);
        $this->assertSame(1_000_000, $this->balance('2110'));

        $return = PurchaseReturn::query()->create(['number' => 'PRT-2', 'trans_date' => '2026-11-06', 'vendor_id' => $this->vendor->id, 'return_type' => 'receipt', 'source_type' => 'goods_receipt', 'source_id' => $gr->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $return->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 3, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 3, 'unit_price' => 100_000, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $this->warehouse->id]);
        $return->refreshTotal();
        $this->docs->created($return);

        $this->assertFalse($return->fresh()->taxable, 'a receipt carries no VAT, so neither does its return');
        $this->assertSame(300_000, $return->fresh()->total);
        $this->assertSame(700_000, $this->balance('2110'), 'goods received not invoiced, less the net value returned');
        $this->assertSame(0, $this->balance('1400'), 'no VAT in to reverse');
    }

    public function test_a_return_takes_stock_out_at_cost_and_credits_the_vendor(): void
    {
        $po = $this->order(10, 100_000);
        $gr = $this->receive($po, 10);
        $invoice = PurchaseInvoice::query()->create(['number' => 'BILL-3', 'trans_date' => '2026-11-05', 'vendor_id' => $this->vendor->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $grLine = $gr->lines()->first();
        $invoice->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 10, 'unit_price' => 100_000, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $this->warehouse->id, 'source_line_type' => 'goods_receipt_line', 'source_line_id' => $grLine->id]);
        $invoice->refreshTotal();
        $this->docs->created($invoice);

        $return = PurchaseReturn::query()->create(['number' => 'PRT-1', 'trans_date' => '2026-11-08', 'vendor_id' => $this->vendor->id, 'return_type' => 'invoice', 'source_type' => 'purchase_invoice', 'source_id' => $invoice->id, 'taxable' => true, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $return->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 3, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 3, 'unit_price' => 100_000, 'tax_code_id' => $this->vat->id, 'warehouse_id' => $this->warehouse->id]);
        $return->refreshTotal();
        $this->docs->created($return);
        $return->refresh();

        $this->assertSame(333_000, $return->total);
        $this->assertSame('7.0000', StockQuery::onHand($this->item->id));
        $this->assertSame(700_000, $this->balance('1300'));
        $this->assertSame(1_110_000 - 333_000, $this->balance('2100'), 'the debit note reduces what is owed');
        $this->assertSame(110_000 - 33_000, $this->balance('1400'));

        // The payment applies the credit note against the invoice.
        $payment = PurchasePayment::query()->create(['number' => 'CB-2', 'trans_date' => '2026-11-12', 'vendor_id' => $this->vendor->id, 'bank_account_id' => Account::query()->where('no', '1102')->value('id'), 'created_by' => auth()->id()]);
        $payment->lines()->createMany([
            ['sort' => 0, 'payable_type' => 'purchase_invoice', 'payable_id' => $invoice->id, 'amount' => 1_110_000, 'discount' => 0],
            ['sort' => 1, 'payable_type' => 'purchase_return', 'payable_id' => $return->id, 'amount' => -333_000, 'discount' => 0],
        ]);
        $payment->refreshTotal();
        $this->docs->created($payment);

        $this->assertSame(777_000, $payment->fresh()->amount);
        $this->assertSame(-777_000, $this->balance('1102'));
        $this->assertSame(0, $this->balance('2100'));
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame('paid', $return->fresh()->payment_status);
    }
}
