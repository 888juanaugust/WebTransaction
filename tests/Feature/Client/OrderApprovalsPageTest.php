<?php

namespace Tests\Feature\Client;

use App\Client\Access\CentralGroups;
use App\Client\Filament\Pages\OrderApprovals;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The Order Approvals worklist: what it shows, who may act, and that approving runs the split. */
class OrderApprovalsPageTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 6);
        $this->stock($this->gudangSurabaya, 10);
    }

    public function test_the_worklist_shows_awaiting_orders_with_their_stock_coverage(): void
    {
        $scattered = $this->order(10, $this->gudangJakarta);
        $covered = $this->order(5, $this->gudangJakarta);

        $this->actingAs($this->marketing);
        Livewire::test(OrderApprovals::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$scattered, $covered])
            ->assertTableColumnStateSet('coverage', '2 warehouses', $scattered)
            ->assertTableColumnStateSet('coverage', 'one warehouse', $covered);
    }

    public function test_sales_opens_the_screen_only_with_the_right_and_never_sees_approve(): void
    {
        $order = $this->order(5, $this->gudangJakarta);

        $this->actingAs($this->sales);
        $this->get('/admin/client/order-approvals')->assertForbidden();

        $this->actingAs($this->marketing);
        $this->get('/admin/client/order-approvals')->assertOk()->assertSee($order->number);
        Livewire::test(OrderApprovals::class)->assertActionVisible(TestAction::make('approve')->table($order));
    }

    public function test_approving_a_scattered_order_from_the_worklist_splits_it(): void
    {
        $order = $this->order(10, $this->gudangJakarta);

        $this->actingAs($this->marketing);
        Livewire::test(OrderApprovals::class)
            ->callAction(TestAction::make('approve')->table($order))
            ->assertNotified();

        $this->assertSame('approved', $order->fresh()->approval_status);
        $sibling = SalesOrder::query()->where('split_parent_id', $order->id)->sole();
        $this->assertSame('approved', $sibling->approval_status);
        $this->assertStringStartsWith('SO-SBY-', $sibling->number);
        Livewire::test(OrderApprovals::class)->assertCanNotSeeTableRecords([$order, $sibling]);
    }

    public function test_another_marketing_sees_the_order_but_not_the_approve_button(): void
    {
        $order = $this->order(5, $this->gudangJakarta);
        $other = $this->member(CentralGroups::MARKETING, [$this->jakarta, $this->surabaya]);

        $this->actingAs($other);
        Livewire::test(OrderApprovals::class)
            ->assertCanSeeTableRecords([$order])
            ->assertActionHidden(TestAction::make('approve')->table($order));
    }
}
