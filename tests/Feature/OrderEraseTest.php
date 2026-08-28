<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Orders\OrderEraser;
use App\Domain\Orders\OrderStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Erasing unfinished orders: marketing's broom, with a narrow reach.
 *
 * The line pinned here is `confirmed`: before it an order is intention and
 * may vanish, after it the order has reserved stock and snapshotted prices
 * and must end through the state machine instead. And an erasure is never
 * silent — the audit row must describe what no longer exists.
 */
class OrderEraseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $marketing;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $region = $this->currentRegion();
        $this->owner = User::factory()->owner()->create();
        $this->marketing = User::factory()->marketing()->create(['region_id' => $region->id]);
        $this->pelanggan = Company::factory()->create();

        app(TeamAssigner::class)->assignMarketing($this->pelanggan, $this->marketing, $this->owner);
    }

    private function orderIn(OrderStatus $status): Order
    {
        $order = Order::factory()->status($status)->create([
            'company_id' => $this->pelanggan->id,
            'total_rupiah' => 1_500_000,
        ]);

        OrderLine::factory()->qty(3)->create(['order_id' => $order->id]);

        return $order;
    }

    public function test_the_marketing_in_charge_erases_a_submitted_order_with_a_trail(): void
    {
        $order = $this->orderIn(OrderStatus::Submitted);
        $nomor = $order->nomor;

        app(OrderEraser::class)->erase($order, $this->marketing, 'Pelanggan membatalkan lewat telepon.');

        $this->assertDatabaseMissing('orders', ['nomor' => $nomor]);

        // The audit row is now the only witness, so it must carry the story.
        $jejak = AuditLog::query()->where('action', 'order_erased')->sole();
        $this->assertSame($nomor, $jejak->old_value['nomor']);
        $this->assertSame($this->pelanggan->nama, $jejak->old_value['pelanggan']);
        $this->assertSame(1_500_000, $jejak->old_value['total_rupiah']);
        $this->assertSame('Pelanggan membatalkan lewat telepon.', $jejak->alasan);
    }

    public function test_sales_cannot_erase(): void
    {
        $sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $order = $this->orderIn(OrderStatus::Draft);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/marketing penanggung jawab/');

        app(OrderEraser::class)->erase($order, $sales);
    }

    public function test_a_marketing_not_in_charge_cannot_erase(): void
    {
        $lain = User::factory()->marketing()->create(['region_id' => $this->currentRegion()->id]);
        $order = $this->orderIn(OrderStatus::Submitted);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/marketing lain/');

        app(OrderEraser::class)->erase($order, $lain);
    }

    public function test_a_confirmed_order_cannot_be_erased_by_anyone(): void
    {
        $order = $this->orderIn(OrderStatus::Confirmed);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/hanya draf/');

        app(OrderEraser::class)->erase($order, $this->owner);
    }

    public function test_the_owner_can_erase_a_draft(): void
    {
        $order = $this->orderIn(OrderStatus::Draft);

        app(OrderEraser::class)->erase($order, $this->owner);

        $this->assertSame(0, Order::query()->count());
    }
}
