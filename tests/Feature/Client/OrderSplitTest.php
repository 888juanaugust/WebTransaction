<?php

namespace Tests\Feature\Client;

use App\Client\Domain\Orders\OrderSplitter;
use App\Client\Domain\Stock\Reservations;
use App\Domain\Approval\ApprovalEngine;
use App\Models\Company\AuditLog;
use App\Models\Sales\SalesOrder;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** An order whose goods sit in several warehouses is split at approval: the home share stays, each other warehouse gets a sibling in its branch, all approved or none. */
class OrderSplitTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
    }

    public function test_the_plan_takes_the_home_warehouse_first_then_the_fullest_other(): void
    {
        $this->stock($this->gudangJakarta, 6);
        $this->stock($this->gudangSurabaya, 10);
        $order = $this->order(10, $this->gudangJakarta);

        $plan = app(OrderSplitter::class)->plan($order);

        $this->assertTrue($plan->needsSplit());
        $this->assertTrue($plan->coversAll());
        $this->assertSame([$this->gudangJakarta->id, $this->gudangSurabaya->id], array_map(fn ($w) => $w->id, $plan->warehouses()));
        $this->assertSame('6.0000', $plan->shares[0]['lines'][0]['quantity']);
        $this->assertSame('4.0000', $plan->shares[1]['lines'][0]['quantity']);
    }

    public function test_an_order_one_warehouse_covers_is_not_split(): void
    {
        $this->stock($this->gudangJakarta, 20);
        $order = $this->order(10, $this->gudangJakarta);

        $plan = app(OrderSplitter::class)->plan($order);
        $this->assertFalse($plan->needsSplit());

        $pieces = app(OrderSplitter::class)->execute($order, $plan, $this->marketing);
        $this->assertCount(1, $pieces);
        $this->assertSame('approved', $order->fresh()->approval_status);
    }

    public function test_the_ordinary_approve_path_refuses_a_scattered_order(): void
    {
        $this->stock($this->gudangJakarta, 6);
        $this->stock($this->gudangSurabaya, 10);
        $order = $this->order(10, $this->gudangJakarta);

        try {
            app(ApprovalEngine::class)->approve($order, $this->marketing);
            $this->fail('the goods sit in two warehouses');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Order Approvals', $e->getMessage());
        }
        $this->assertSame('awaiting', $order->fresh()->approval_status);
    }

    public function test_executing_the_plan_splits_numbers_each_piece_in_its_branch_and_reserves_all(): void
    {
        $this->stock($this->gudangJakarta, 6);
        $this->stock($this->gudangSurabaya, 10);
        $order = $this->order(10, $this->gudangJakarta);
        $total = $order->total;

        $pieces = app(OrderSplitter::class)->execute($order, app(OrderSplitter::class)->plan($order), $this->marketing);

        $this->assertCount(2, $pieces);
        [$parent, $sibling] = $pieces;
        $this->assertSame($order->id, $parent->id);
        $this->assertSame('approved', $parent->approval_status);
        $this->assertSame('6.0000', $parent->lines()->first()->base_quantity);
        $this->assertSame($this->jakarta->id, $parent->branch_id);

        $this->assertSame('approved', $sibling->approval_status);
        $this->assertSame($order->id, $sibling->split_parent_id);
        $this->assertSame($this->surabaya->id, $sibling->branch_id);
        $this->assertStringStartsWith('SO-SBY-2610-', $sibling->number);
        $this->assertSame('4.0000', $sibling->lines()->first()->base_quantity);
        $this->assertSame($this->gudangSurabaya->id, $sibling->lines()->first()->warehouse_id);
        $this->assertSame($this->sales->id, $sibling->created_by, 'the sibling is the sales person\'s order too');
        $this->assertSame($total, $parent->total + $sibling->total, 'the pieces add up to the order');

        $this->assertSame('6.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id));
        $this->assertSame('4.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangSurabaya->id));
        $this->assertNotNull(AuditLog::query()->where('action', 'order_split')->where('document_id', $order->id)->first());
    }

    public function test_a_customer_whose_home_holds_nothing_is_re_homed_not_split(): void
    {
        $this->stock($this->gudangSurabaya, 10);
        $order = $this->order(10, $this->gudangJakarta);
        $plan = app(OrderSplitter::class)->plan($order);
        $this->assertFalse($plan->needsSplit());
        $this->assertSame($this->gudangSurabaya->id, $plan->warehouses()[0]->id);

        $pieces = app(OrderSplitter::class)->execute($order, $plan, $this->marketing);

        $this->assertCount(1, $pieces);
        $order->refresh();
        $this->assertSame('approved', $order->approval_status);
        $this->assertSame($this->gudangSurabaya->id, $order->lines()->first()->warehouse_id, 'the order ships from Surabaya');
        $this->assertSame($this->surabaya->id, $order->branch_id, 'and is booked in that branch');
        $this->assertStringStartsWith('SO-SBY-2610-', $order->number);
        $this->assertSame('10.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangSurabaya->id));
    }

    public function test_a_shortfall_is_refused_before_anything_changes(): void
    {
        $this->stock($this->gudangJakarta, 6);
        $this->stock($this->gudangSurabaya, 2);
        $order = $this->order(10, $this->gudangJakarta);
        $plan = app(OrderSplitter::class)->plan($order);
        $this->assertFalse($plan->coversAll());
        $this->assertSame('2.0000', $plan->shortfalls[0]['quantity']);

        $this->expectExceptionMessage('No warehouse can cover');
        app(OrderSplitter::class)->execute($order, $plan, $this->marketing);
    }

    public function test_a_credit_failure_on_the_last_piece_rolls_the_whole_split_back(): void
    {
        $this->stock($this->gudangJakarta, 6);
        $this->stock($this->gudangSurabaya, 10);
        $order = $this->order(10, $this->gudangJakarta);
        // The parent piece fits the limit; the sibling tips it over.
        $this->customer->update(['credit_limit_amount_enabled' => true, 'credit_limit_amount' => 1_100_000]);
        $this->actingAs($this->marketing);

        try {
            app(OrderSplitter::class)->execute($order, app(OrderSplitter::class)->plan($order), $this->marketing);
            $this->fail('over the credit limit on the second piece');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('credit limit', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame('awaiting', $order->approval_status);
        $this->assertSame('10.0000', $order->lines()->first()->base_quantity, 'the parent is whole again');
        $this->assertSame(0, SalesOrder::query()->where('split_parent_id', $order->id)->count());
        $this->assertSame('0.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id));
    }
}
