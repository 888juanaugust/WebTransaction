<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockQuery;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Filament\Resources\Sales\Deliveries\DeliveryResource;
use App\Filament\Resources\Sales\Deliveries\Pages\CreateDelivery;
use App\Filament\Resources\Sales\SalesInvoices\Pages\CreateSalesInvoice;
use App\Filament\Resources\Sales\SalesOrders\Pages\CreateSalesOrder;
use App\Filament\Resources\Sales\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\Sales\SalesReceipts\Pages\CreateSalesReceipt;
use App\Models\Company\TaxCode;
use App\Models\Company\TransactionApprover;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\Customer;
use App\Models\Sales\Delivery;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesReceipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SalesScreensTest extends TestCase
{
    private Customer $customer;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->customer = $this->sampleCustomer();
        $this->item = $this->sampleItem(['tax1_id' => TaxCode::default()->id]);

        $opening = InventoryAdjustment::query()->create(['number' => 'ADJ-OPEN', 'trans_date' => '2026-10-01', 'created_by' => auth()->id()]);
        $opening->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 20, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 20, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => Warehouse::default()->id]);
        app(DocumentRepository::class)->created($opening);
    }

    public function test_the_chain_runs_through_the_screens_with_order_approval(): void
    {
        app(Preferensi::class)->set(PreferensiKey::SalesOrderApproval, true);
        $approver = User::factory()->create(['access_type' => 'administrator']);
        $rule = TransactionApprover::query()->create(['transaction_type' => 'sales_order', 'min_amount' => 0, 'rule' => 'any_one', 'is_active' => true]);
        $rule->approvers()->attach([auth()->id(), $approver->id]);

        Livewire::test(CreateSalesOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->id,
                'trans_date' => '2026-11-01',
                'taxable' => true,
                'inclusive_tax' => false,
                'lines' => [['item_id' => $this->item->id, 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'unit_price' => 150_000, 'discount_percent' => 0, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => Warehouse::default()->id]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = SalesOrder::query()->firstOrFail();
        $this->assertSame('SO-PST-2611-0001', $order->number);
        $this->assertSame(1_665_000, $order->total);
        $this->assertSame('awaiting', $order->approval_status);

        // Awaiting orders cannot ship; the admin who entered it is named by the rule but is not offered Approve (segregation of duties).
        Livewire::test(ListSalesOrders::class)
            ->assertTableActionHidden('deliver', $order)
            ->assertTableActionHidden('approve', $order);
        $this->assertSame('awaiting', $order->fresh()->approval_status);

        // Someone the rule does not name sees no approve button at all.
        $this->actingAs(User::factory()->create(['access_type' => 'administrator']));
        Livewire::test(ListSalesOrders::class)->assertTableActionHidden('approve', $order);

        $this->actingAs($approver);
        Livewire::test(ListSalesOrders::class)
            ->callTableAction('approve', $order)
            ->assertNotified('SO-PST-2611-0001 approved');
        $this->assertSame('approved', $order->fresh()->approval_status);
        $this->assertSame($approver->id, $order->fresh()->approved_by);

        $this->actingAsAdmin();
        Livewire::test(ListSalesOrders::class)->assertTableActionVisible('deliver', $order);
        $this->get(DeliveryResource::getUrl('create', ['source' => $order->id]))->assertOk()->assertSee('From order SO-PST-2611-0001');

        Livewire::withQueryParams(['source' => $order->id])
            ->test(CreateDelivery::class)
            ->assertSchemaStateSet(['customer_id' => $this->customer->id])
            ->set('data.trans_date', '2026-11-03')
            ->call('create')
            ->assertHasNoFormErrors();

        $delivery = Delivery::query()->firstOrFail();
        $this->assertSame('SJ-PST-2611-0001', $delivery->number);
        $this->assertSame('10.0000', $delivery->lines()->first()->base_quantity);
        $this->assertSame('sales_order_line', $delivery->lines()->first()->source_line_type);
        $this->assertSame('10.0000', StockQuery::onHand($this->item->id), 'goods left the warehouse at delivery');
        $this->assertSame('processed', $order->fresh()->status);

        Livewire::withQueryParams(['source' => 'delivery:'.$delivery->id])
            ->test(CreateSalesInvoice::class)
            ->assertSchemaStateSet(['customer_id' => $this->customer->id])
            ->set('data.trans_date', '2026-11-05')
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = SalesInvoice::query()->firstOrFail();
        $this->assertSame('INV-PST-2611-0001', $invoice->number);
        $this->assertSame(1_665_000, $invoice->total, 'billed at the order price carried through the delivery');
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame('processed', $delivery->fresh()->status);

        Livewire::withQueryParams(['source' => 'sales_invoice:'.$invoice->id])
            ->test(CreateSalesReceipt::class)
            ->set('data.bank_account_id', Account::query()->where('no', '1102')->value('id'))
            ->set('data.trans_date', '2026-11-10')
            ->call('create')
            ->assertHasNoFormErrors();

        $receipt = SalesReceipt::query()->firstOrFail();
        $this->assertSame(1_665_000, $receipt->amount);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame('sales_invoice', $receipt->lines()->first()->receivable_type);
    }
}
