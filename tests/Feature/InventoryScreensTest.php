<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockQuery;
use App\Domain\Posting\DocumentRepository;
use App\Filament\Pages\Inventory\MinimumStock;
use App\Filament\Pages\Inventory\StockByWarehouse;
use App\Filament\Resources\Inventory\InventoryAdjustments\Pages\CreateInventoryAdjustment;
use App\Filament\Resources\Inventory\InventoryAdjustments\Pages\EditInventoryAdjustment;
use App\Filament\Resources\Inventory\ItemTransfers\Pages\CreateItemTransfer;
use App\Filament\Resources\Inventory\ItemTransfers\Pages\ListItemTransfers;
use App\Filament\Resources\Inventory\StockOpnameResults\Pages\EditStockOpnameResult;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemTransfer;
use App\Models\Inventory\StockOpnameOrder;
use App\Models\Inventory\StockOpnameResult;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryScreensTest extends TestCase
{
    private Item $item;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $pcs = Unit::query()->where('name', 'PCS')->value('id');
        $ctn = Unit::query()->where('name', 'CTN')->value('id');
        $this->item = Item::query()->create(['number' => 'ITM-00001', 'name' => 'Oil filter', 'unit1_id' => $pcs, 'min_stock' => 20]);
        $this->item->units()->create(['unit_id' => $ctn, 'ratio' => 12]);
        $this->main = Warehouse::default();
    }

    public function test_an_adjustment_in_cartons_lands_in_base_units_and_can_be_edited(): void
    {
        $ctn = Unit::query()->where('name', 'CTN')->value('id');

        Livewire::test(CreateInventoryAdjustment::class)
            ->fillForm([
                'trans_date' => '2026-11-10',
                'description' => 'Found in the back room',
                'lines' => [['item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 2, 'unit_id' => $ctn, 'unit_cost' => 40_000, 'warehouse_id' => $this->main->id]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $adjustment = InventoryAdjustment::query()->firstOrFail();
        $this->assertSame('ADJ-PST-2611-0001', $adjustment->number);
        $this->assertSame('24.0000', $adjustment->lines()->first()->base_quantity);
        $this->assertSame('24.0000', StockQuery::onHand($this->item->id));

        Livewire::test(EditInventoryAdjustment::class, ['record' => $adjustment->getRouteKey()])
            ->fillForm(['lines' => [['item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 3, 'unit_id' => $ctn, 'unit_cost' => 40_000, 'warehouse_id' => $this->main->id]]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('36.0000', StockQuery::onHand($this->item->id));

        Livewire::test(StockByWarehouse::class)
            ->fillForm(['item_id' => $this->item->id])
            ->assertSee('Main Warehouse')
            ->assertSee('36 PCS · 3 CTN');

        Livewire::test(MinimumStock::class)->assertDontSee('Oil filter');
        $this->item->update(['min_stock' => 40]);
        Livewire::test(MinimumStock::class)->assertSee('Oil filter');
    }

    public function test_a_transfer_is_sent_from_the_form_and_received_from_the_list(): void
    {
        $branch = Warehouse::query()->create(['name' => 'Branch B']);
        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-X', 'trans_date' => '2026-11-01', 'created_by' => auth()->id()]);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 10, 'unit_cost' => 30_000, 'warehouse_id' => $this->main->id]);
        app(DocumentRepository::class)->created($adjustment);

        Livewire::test(CreateItemTransfer::class)
            ->fillForm([
                'warehouse_id' => $this->main->id,
                'reference_warehouse_id' => $branch->id,
                'trans_date' => '2026-11-05',
                'lines' => [['item_id' => $this->item->id, 'quantity' => 6, 'unit_id' => $this->item->unit1_id]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $send = ItemTransfer::query()->firstOrFail();
        $this->assertSame('pending', $send->status);
        $this->assertSame('4.0000', StockQuery::onHand($this->item->id, $this->main->id));

        Livewire::test(ListItemTransfers::class)
            ->callTableAction('receive', $send, data: [
                'trans_date' => '2026-11-06',
                'quantities' => [['line_id' => $send->lines()->first()->id, 'quantity' => 6]],
            ])
            ->assertNotified();

        $this->assertSame('processed', $send->fresh()->status);
        $this->assertSame('6.0000', StockQuery::onHand($this->item->id, $branch->id));
        $this->assertSame(2, ItemTransfer::query()->count());
    }

    public function test_an_approved_count_cannot_be_approved_again_and_posts_once(): void
    {
        $adjustment = InventoryAdjustment::query()->create(['number' => 'ADJ-X', 'trans_date' => '2026-11-01', 'created_by' => auth()->id()]);
        $adjustment->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity', 'quantity' => 10, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 10, 'unit_cost' => 30_000, 'warehouse_id' => $this->main->id]);
        app(DocumentRepository::class)->created($adjustment);

        $counter = User::factory()->create();
        $order = StockOpnameOrder::query()->create(['number' => 'SOO-1', 'trans_date' => '2026-11-09', 'start_date' => '2026-11-10', 'person_charged' => 'Alex Doe', 'warehouse_id' => $this->main->id, 'created_by' => $counter->id]);
        $result = StockOpnameResult::query()->create(['number' => 'SOR-1', 'trans_date' => '2026-11-10', 'stock_opname_order_id' => $order->id, 'created_by' => $counter->id]);
        $result->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'counted_qty' => 13, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 13, 'system_qty' => 10]);

        Livewire::test(EditStockOpnameResult::class, ['record' => $result->getRouteKey()])
            ->callAction('approve')
            ->assertNotified();

        $this->assertSame('approved', $result->fresh()->status);
        $this->assertSame('13.0000', StockQuery::onHand($this->item->id));
        $this->assertSame(2, InventoryAdjustment::query()->count());

        Livewire::test(EditStockOpnameResult::class, ['record' => $result->getRouteKey()])
            ->assertActionHidden('approve');
    }
}
