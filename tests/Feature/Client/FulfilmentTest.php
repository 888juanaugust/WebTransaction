<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Domain\Stock\Reservations;
use App\Client\Domain\Warehouse\DeliveryMaker;
use App\Client\Domain\Warehouse\WarehouseBinder;
use App\Client\Filament\Pages\Fulfilment;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Inventory\StockQuery;
use App\Models\Sales\Delivery;
use App\Models\User;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The fulfilment queue: a bound account sees its warehouse's held orders and Deliver makes the delivery that consumes the holds. */
class FulfilmentTest extends TestCase
{
    use OrderFlow;

    private User $gudang;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 20);
        $this->stock($this->gudangSurabaya, 20);
        $this->gudang = $this->member(CentralGroups::WAREHOUSE, [$this->jakarta]);
        app(WarehouseBinder::class)->bind($this->gudangJakarta, $this->gudang, $this->owner);
    }

    public function test_the_queue_shows_the_warehouses_held_orders_and_deliver_consumes_the_holds(): void
    {
        $jakarta = $this->order(5, $this->gudangJakarta);
        $surabaya = $this->order(3, $this->gudangSurabaya);
        app(ApprovalEngine::class)->approve($jakarta, $this->marketing);
        app(ApprovalEngine::class)->approve($surabaya, $this->marketing);
        $this->assertSame('5.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id));

        $this->actingAs($this->gudang);
        $this->get('/admin/client/fulfilment')->assertOk();
        Livewire::test(Fulfilment::class)->assertOk()
            ->assertSee($jakarta->number)->assertDontSee($surabaya->number)
            ->assertTableActionVisible('deliver', $jakarta)
            ->callTableAction('deliver', $jakarta, ['trans_date' => today()->toDateString(), 'lines' => [['line_id' => $jakarta->lines()->first()->id, 'item' => 'x', 'held' => '5', 'quantity' => 5]]])
            ->assertHasNoTableActionErrors();

        $delivery = Delivery::query()->sole();
        $this->assertSame($this->gudang->id, $delivery->created_by);
        $this->assertStringStartsWith('SJ-JKT-', $delivery->number);
        $this->assertSame('5.0000', $delivery->lines()->first()->base_quantity);
        $this->assertSame('sales_order_line', $delivery->lines()->first()->source_line_type);
        $this->assertSame('0.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id), 'the holds are consumed');
        $this->assertSame('15.0000', StockQuery::onHand($this->item->id, $this->gudangJakarta->id));
        Livewire::test(Fulfilment::class)->assertDontSee($jakarta->number);
    }

    public function test_a_partial_delivery_leaves_the_rest_held_and_never_exceeds_the_hold(): void
    {
        $order = $this->order(5, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $line = $order->lines()->first();
        $this->actingAs($this->gudang);

        try {
            app(DeliveryMaker::class)->make($order, $this->gudangJakarta, [$line->id => 6], $this->gudang);
            $this->fail('more than held');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('holds 5.0000 for this order, not 6', $e->getMessage());
        }
        try {
            app(DeliveryMaker::class)->make($order, $this->gudangSurabaya, [], $this->gudang);
            $this->fail('nothing held there');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('holds nothing', $e->getMessage());
        }

        app(DeliveryMaker::class)->make($order, $this->gudangJakarta, [$line->id => 2], $this->gudang);
        $this->assertSame('3.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id));
        $this->assertSame([$order->id], app(DeliveryMaker::class)->held($order, $this->gudangJakarta) === [] ? [] : [$order->id], 'still in the queue');

        app(DeliveryMaker::class)->make($order, $this->gudangJakarta, [], $this->gudang);
        $this->assertSame('0.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id));
        $this->assertSame(2, Delivery::query()->count());
    }

    public function test_inventory_picks_a_warehouse_and_a_gudang_account_sees_nothing_else(): void
    {
        $surabaya = $this->order(3, $this->gudangSurabaya);
        app(ApprovalEngine::class)->approve($surabaya, $this->marketing);

        $this->actingAs($this->inventory);
        Livewire::test(Fulfilment::class)->assertOk()
            ->filterTable('warehouse', $this->gudangSurabaya->id)
            ->assertSee($surabaya->number);

        $this->actingAs($this->gudang);
        Livewire::test(Fulfilment::class)->assertDontSee($surabaya->number);
        $this->get('/admin/customer/sales-order')->assertForbidden();
        $this->get('/admin/client/collections')->assertForbidden();
    }
}
