<?php

namespace Tests\Feature\Client;

use App\Client\Domain\Stock\InsufficientStockException;
use App\Client\Domain\Stock\Reservations;
use App\Client\Models\StockReservation;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Inventory\StockQuery;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\DocumentRepository;
use App\Models\Sales\SalesOrder;
use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\Feature\Client\Support\OrderFlow;
use Tests\TestCase;

/** The reservations ledger: an approved order holds its goods until the delivery takes them, a rejection lets them go, and nothing held leaves for another order. */
class StockReservationTest extends TestCase
{
    use OrderFlow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareOrderFlow();
        $this->stock($this->gudangJakarta, 20);
    }

    private function held(SalesOrder $order): string
    {
        return collect(app(Reservations::class)->heldByOrder($order))->sum(fn (array $h) => (float) $h['quantity']) === 0.0 ? '0.0000' : app(Reservations::class)->heldByOrder($order)[0]['quantity'];
    }

    public function test_approval_holds_the_goods_and_a_delivery_consumes_them(): void
    {
        $order = $this->order(8, $this->gudangJakarta);
        $this->assertSame([], app(Reservations::class)->heldByOrder($order), 'nothing is held while the order waits');
        $this->assertSame('20.0000', app(Reservations::class)->available($this->item->id, $this->gudangJakarta->id));

        app(ApprovalEngine::class)->approve($order, $this->marketing);

        $this->assertSame('approved', $order->fresh()->approval_status);
        $this->assertSame('8.0000', $this->held($order));
        $this->assertSame('8.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id));
        $this->assertSame('12.0000', app(Reservations::class)->available($this->item->id, $this->gudangJakarta->id));
        $this->assertSame('20.0000', StockQuery::onHand($this->item->id), 'the shelf is untouched until the delivery');

        $delivery = $this->deliver($order, 5);
        $this->assertSame('3.0000', $this->held($order), 'a partial delivery consumes its share');
        $this->assertSame('15.0000', StockQuery::onHand($this->item->id));
        $this->assertSame('12.0000', app(Reservations::class)->available($this->item->id, $this->gudangJakarta->id), 'free stock is unchanged: the goods that left were the ones held');
        $consumed = StockReservation::query()->where('sales_order_id', $order->id)->where('kind', StockReservation::CONSUMED)->sole();
        $this->assertSame('-5.0000', $consumed->quantity);
        $this->assertSame($delivery->fresh()->posting->id, $consumed->posting_id);

        $this->deliver($order, 3);
        $this->assertSame([], app(Reservations::class)->heldByOrder($order->fresh()));
        $this->assertSame('processed', $order->fresh()->status);
    }

    public function test_an_order_over_the_free_stock_is_refused_and_nothing_is_held(): void
    {
        $first = $this->order(15, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($first, $this->marketing);
        $second = $this->order(10, $this->gudangJakarta);

        try {
            app(ApprovalEngine::class)->approve($second, $this->marketing);
            $this->fail('only 5 are free');
        } catch (InsufficientStockException $e) {
            $this->assertSame('5.0000', $e->short);
            $this->assertSame('5.0000', $e->available);
            $this->assertStringContainsString('Widget', $e->getMessage());
        }
        $this->assertSame('awaiting', $second->fresh()->approval_status, 'the approval rolled back with the reservation');
        $this->assertSame([], app(Reservations::class)->heldByOrder($second));
        $this->assertSame('15.0000', app(Reservations::class)->heldSum($this->item->id, $this->gudangJakarta->id));
    }

    public function test_goods_held_for_one_order_never_leave_for_another(): void
    {
        $reserved = $this->order(15, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($reserved, $this->marketing);
        app(Preferensi::class)->set(PreferensiKey::SalesOrderApproval, false);
        $walkIn = $this->order(8, $this->gudangJakarta); // approved on entry, nothing held

        try {
            $this->deliver($walkIn, 8);
            $this->fail('only 5 are free');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Other orders hold', $e->getMessage());
        }
        $this->assertSame('20.0000', StockQuery::onHand($this->item->id), 'the delivery rolled back');
        $this->assertSame('pending', $walkIn->fresh()->status);
    }

    public function test_rejection_and_a_reopened_approval_release_the_hold(): void
    {
        $order = $this->order(8, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $this->assertSame('8.0000', $this->held($order));

        // An edit that changes the lines asks for approval again: the hold goes until it is given again.
        $order->lines()->first()->forceFill(['quantity' => 6, 'base_quantity' => 6])->saveQuietly();
        $order->refreshTotal();
        $this->docs->updated($order, ['header' => [], 'lines' => [], 'sources' => []]);
        $this->assertSame('awaiting', $order->fresh()->approval_status);
        $this->assertSame([], app(Reservations::class)->heldByOrder($order));
        $this->assertSame('released', StockReservation::query()->where('sales_order_id', $order->id)->latest('id')->value('kind'));

        app(ApprovalEngine::class)->approve($order->fresh(), $this->marketing);
        $this->assertSame('6.0000', $this->held($order));
        $this->assertSame('14.0000', app(Reservations::class)->available($this->item->id, $this->gudangJakarta->id));

        // A rejected order holds nothing (only an awaiting order can be rejected, and it holds nothing yet).
        $other = $this->order(4, $this->gudangJakarta);
        app(ApprovalEngine::class)->reject($other, $this->marketing, 'customer cancelled');
        $this->assertSame('rejected', $other->fresh()->approval_status);
        $this->assertSame([], app(Reservations::class)->heldByOrder($other));
        $this->assertSame('14.0000', app(Reservations::class)->available($this->item->id, $this->gudangJakarta->id));
    }

    public function test_an_unposted_delivery_gives_the_hold_back_and_a_re_posted_one_does_not_double_count(): void
    {
        $order = $this->order(8, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $delivery = $this->deliver($order, 5);
        $this->assertSame('3.0000', $this->held($order));

        // The delivery is edited: 5 becomes 4. Its posting is superseded and written again.
        $delivery->lines()->first()->forceFill(['quantity' => 4, 'base_quantity' => 4])->saveQuietly();
        $delivery->refreshTotal();
        $this->docs->updated($delivery, $delivery->snapshot() + ['sources' => []]);
        $this->assertSame('4.0000', $this->held($order), 'held = 8 reserved − 4 delivered');
        $this->assertSame('16.0000', StockQuery::onHand($this->item->id));

        app(DocumentRepository::class)->delete($delivery->fresh());
        $this->assertSame('8.0000', $this->held($order), 'the whole hold is back');
        $this->assertSame('20.0000', StockQuery::onHand($this->item->id));
        $this->assertSame('12.0000', app(Reservations::class)->available($this->item->id, $this->gudangJakarta->id));
    }

    public function test_a_delivery_from_another_warehouse_releases_the_hold_instead(): void
    {
        $this->stock($this->gudangSurabaya, 10);
        $order = $this->order(8, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);

        $this->deliver($order, 8, $this->gudangSurabaya);

        $this->assertSame([], app(Reservations::class)->heldByOrder($order));
        $this->assertSame('delivered_elsewhere', StockReservation::query()->where('sales_order_id', $order->id)->where('kind', StockReservation::RELEASED)->value('reason'));
        $this->assertSame('20.0000', app(Reservations::class)->available($this->item->id, $this->gudangJakarta->id));
        $this->assertSame('2.0000', StockQuery::onHand($this->item->id, $this->gudangSurabaya->id));
    }

    public function test_the_ledger_is_append_only(): void
    {
        $order = $this->order(8, $this->gudangJakarta);
        app(ApprovalEngine::class)->approve($order, $this->marketing);
        $row = StockReservation::query()->where('sales_order_id', $order->id)->sole();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        StockReservation::query()->whereKey($row->id)->update(['quantity' => 1]);
    }

    public function test_with_the_approval_rule_off_nothing_is_held(): void
    {
        app(Preferensi::class)->set(PreferensiKey::SalesOrderApproval, false);
        $order = $this->order(8, $this->gudangJakarta);

        $this->assertSame('approved', $order->approval_status, 'approved on entry');
        $this->assertSame([], app(Reservations::class)->heldByOrder($order));
    }
}
