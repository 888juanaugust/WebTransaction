<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockQuery;
use App\Filament\Resources\Purchasing\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\Purchasing\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\Purchasing\PurchaseInvoices\Pages\CreatePurchaseInvoice;
use App\Filament\Resources\Purchasing\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\Purchasing\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\Purchasing\PurchasePayments\Pages\CreatePurchasePayment;
use App\Models\Company\TaxCode;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\GoodsReceipt;
use App\Models\Purchasing\PurchaseInvoice;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchasePayment;
use App\Models\Purchasing\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasingScreensTest extends TestCase
{
    private Vendor $vendor;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->vendor = $this->sampleVendor(['default_inc_tax' => false]);
        $this->item = Item::query()->create(['number' => 'ITM-00001', 'name' => 'Widget', 'unit1_id' => Unit::query()->where('name', 'PCS')->value('id'), 'purchase_price' => 100_000, 'tax1_id' => TaxCode::default()->id]);
    }

    public function test_the_chain_runs_through_the_screens(): void
    {
        Livewire::test(CreatePurchaseOrder::class)
            ->fillForm([
                'vendor_id' => $this->vendor->id,
                'trans_date' => '2026-11-01',
                'taxable' => true,
                'inclusive_tax' => false,
                'lines' => [['item_id' => $this->item->id, 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'unit_price' => 100_000, 'discount_percent' => 0, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $po = PurchaseOrder::query()->firstOrFail();
        $this->assertSame('PO-2611-0001', $po->number);
        $this->assertSame(1_110_000, $po->total);
        $this->assertSame('pending', $po->status);

        // "Receive" opens the receipt pre-filled from the order.
        Livewire::test(ListPurchaseOrders::class)->assertTableActionVisible('receive', $po);
        $this->get(GoodsReceiptResource::getUrl('create', ['source' => $po->id]))->assertOk()->assertSee('From order PO-2611-0001');

        Livewire::withQueryParams(['source' => $po->id])
            ->test(CreateGoodsReceipt::class)
            ->assertSchemaStateSet(['vendor_id' => $this->vendor->id])
            ->set('data.trans_date', '2026-11-03')
            ->set('data.receive_number', 'SJ-77')
            ->call('create')
            ->assertHasNoFormErrors();

        $gr = GoodsReceipt::query()->firstOrFail();
        $this->assertSame('10.0000', $gr->lines()->first()->base_quantity);
        $this->assertSame('purchase_order_line', $gr->lines()->first()->source_line_type);
        $this->assertSame('10.0000', StockQuery::onHand($this->item->id));
        $this->assertSame('processed', $po->fresh()->status);

        Livewire::withQueryParams(['source' => 'receipt:'.$gr->id])
            ->test(CreatePurchaseInvoice::class)
            ->set('data.trans_date', '2026-11-05')
            ->set('data.bill_number', 'INV/SP/88')
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = PurchaseInvoice::query()->firstOrFail();
        $this->assertSame(1_110_000, $invoice->total);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame('processed', $gr->fresh()->status);

        Livewire::withQueryParams(['source' => 'purchase_invoice:'.$invoice->id])
            ->test(CreatePurchasePayment::class)
            ->set('data.bank_account_id', Account::query()->where('no', '1102')->value('id'))
            ->set('data.trans_date', '2026-11-10')
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = PurchasePayment::query()->firstOrFail();
        $this->assertSame(1_110_000, $payment->amount);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame('purchase_invoice', $payment->lines()->first()->payable_type);
    }
}
