<?php

namespace Tests\Feature\Domain;

use App\Domain\Inventory\Replenishment;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Sales\OrderApproval;
use App\Domain\Shared\Format;
use App\Filament\Pages\Inventory\MinimumStock;
use App\Filament\Pages\Inventory\OrderFulfilment;
use App\Filament\Resources\Purchasing\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Company\TaxCode;
use App\Models\Company\TransactionApprover;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Purchasing\PurchaseOrder;
use App\Models\Purchasing\PurchaseRequisition;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\SalesOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Minimum stock per warehouse, what is on order and requested, and what to order. */
class ReorderingTest extends TestCase
{
    private DocumentRepository $docs;

    private Item $item;

    private Vendor $vendor;

    private Warehouse $main;

    private Warehouse $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));
        $this->seed();
        $this->actingAsAdmin();
        $this->docs = app(DocumentRepository::class);
        $this->vendor = $this->sampleVendor();
        $this->main = Warehouse::default();
        $this->second = Warehouse::query()->create(['name' => 'Second']);
        $this->item = $this->sampleItem(['min_stock' => 15, 'preferred_vendor_id' => $this->vendor->id])->fresh();
        $this->item->minimumStocks()->createMany([['warehouse_id' => $this->main->id, 'quantity' => 6], ['warehouse_id' => $this->second->id, 'quantity' => 3]]);

        foreach ([[$this->main, 4], [$this->second, 5]] as [$warehouse, $qty]) {
            $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-'.$warehouse->id, 'trans_date' => '2026-11-01', 'created_by' => auth()->id()]);
            $adjustment->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_cost' => 100_000, 'total_cost' => 0, 'warehouse_id' => $warehouse->id]);
            $this->docs->created($adjustment);
        }

        // Three on an approved order; five on an order still waiting for approval; two asked for on a requisition.
        $rule = TransactionApprover::query()->create(['transaction_type' => 'purchase_order', 'min_amount' => 1_000_000, 'rule' => 'any_one', 'is_active' => true]);
        $rule->approvers()->attach(User::factory()->create());
        $this->order(3, 100_000);
        $this->order(5, 300_000);
        $requisition = PurchaseRequisition::query()->create(['number' => 'PR-1', 'trans_date' => '2026-11-10', 'created_by' => auth()->id()]);
        $requisition->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 2, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 2]);
        $this->docs->created($requisition);
    }

    private function order(int $qty, int $price): PurchaseOrder
    {
        $po = PurchaseOrder::query()->create(['number' => 'PO-'.uniqid(), 'trans_date' => '2026-11-10', 'vendor_id' => $this->vendor->id, 'taxable' => false, 'inclusive_tax' => false, 'created_by' => auth()->id()]);
        $po->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty, 'unit_price' => $price, 'warehouse_id' => $this->main->id]);
        $po->refreshTotal();
        $this->docs->created($po);

        return $po;
    }

    public function test_on_order_and_requested_count_only_open_approved_documents_and_minimums_follow_the_warehouse(): void
    {
        $this->assertSame('3', rtrim(rtrim(Replenishment::onOrder()[$this->item->id], '0'), '.'), 'the order waiting for approval is not counted');
        $this->assertSame('2', rtrim(rtrim(Replenishment::requested()[$this->item->id], '0'), '.'));

        $all = Replenishment::belowMinimum()->first();
        $this->assertSame('15.0000', (string) Replenishment::minimumOf($this->item, null), 'the overall minimum, larger than the warehouses\' 9');
        $this->assertSame('1', rtrim(rtrim($all['to_order'], '0'), '.'), '15 − 9 on hand − 3 on order − 2 requested');

        $main = Replenishment::belowMinimum($this->main->id)->first();
        $this->assertSame('6.0000', $main['minimum'], 'the warehouse\'s own minimum');
        $this->assertSame('0', $main['to_order'], 'what is coming covers it');
        $this->assertTrue(Replenishment::belowMinimum($this->second->id)->isEmpty(), 'five on hand, minimum three');
    }

    public function test_ordering_from_minimum_stock_opens_a_purchase_order_with_the_lines_to_order(): void
    {
        Livewire::test(MinimumStock::class)
            ->assertSee('Widget')
            ->callTableBulkAction('order', [$this->item->id])
            ->assertRedirectContains('reorder='.urlencode($this->item->id.':1'));

        Livewire::withQueryParams(['reorder' => $this->item->id.':1.0000', 'warehouse' => $this->second->id])
            ->test(CreatePurchaseOrder::class)
            ->assertSet('data.vendor_id', $this->vendor->id)
            ->assertSet('data.lines', fn (array $lines) => count($lines) === 1
                && (int) reset($lines)['item_id'] === $this->item->id
                && (float) reset($lines)['quantity'] === 1.0
                && (int) reset($lines)['warehouse_id'] === $this->second->id);
    }

    public function test_order_fulfilment_shows_what_stock_and_open_orders_cannot_cover(): void
    {
        $customer = $this->sampleCustomer();
        $order = SalesOrder::query()->create(['number' => 'SO-1', 'trans_date' => '2026-11-12', 'customer_id' => $customer->id, 'taxable' => false, 'inclusive_tax' => false, 'approval_status' => app(OrderApproval::class)->initialStatus(), 'created_by' => auth()->id()]);
        $order->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 20, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 20, 'unit_price' => 150_000, 'tax_code_id' => TaxCode::default()->id, 'warehouse_id' => $this->main->id]);
        $order->refreshTotal();
        $this->docs->created($order);

        $page = Livewire::test(OrderFulfilment::class)->assertSee('SO-1')->assertSee('Need to order');
        $rows = (fn () => $this->rows())->call($page->instance());
        $this->assertSame(Format::quantity('8'), $rows->first()['need_to_order'], '20 ordered − 9 on hand − 3 on order');
    }
}
