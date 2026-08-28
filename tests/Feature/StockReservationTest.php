<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\InsufficientStockException;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Uom\Unit;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock is an append-only ledger; the cached level must always be
 * reconstructible from it. Reservations fence stock at `confirmed`; the
 * decrement happens at `shipped`.
 */
class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create(['kode' => 'YH-1001', 'qty_per_ctn' => 12]);
        $this->sales = User::factory()->sales()->create();

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        PriceListItem::factory()->create([
            'version_id' => $version->id,
            'kode' => $this->product->kode,
            'harga' => 100_000,
        ]);
    }

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    private function stockUp(int $qty): void
    {
        $this->ledger()->record(
            sku: $this->product->kode,
            warehouseId: $this->warehouse->id,
            qtySigned: $qty,
            reason: MovementReason::Penerimaan,
        );
    }

    private function orderFor(int $qtyBase, ?Company $company = null): Order
    {
        $company ??= Company::factory()->creditLimit(1_000_000_000)->create();

        $order = Order::factory()->status(OrderStatus::Submitted)->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
        ]);

        OrderLine::factory()->qty($qtyBase)->create([
            'order_id' => $order->id,
            'sku' => $this->product->kode,
        ]);

        return $order->refresh();
    }

    // --- the ledger itself -------------------------------------------------

    public function test_receiving_stock_writes_a_movement_and_updates_the_cache(): void
    {
        $this->stockUp(100);

        $this->assertSame(100, $this->ledger()->available($this->product->kode, $this->warehouse->id));
        $this->assertSame(100, $this->ledger()->onHandFromLedger($this->product->kode, $this->warehouse->id));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_the_cached_level_always_matches_the_summed_ledger(): void
    {
        $this->stockUp(100);
        $this->stockUp(50);
        $this->ledger()->record(
            $this->product->kode, $this->warehouse->id, -30, MovementReason::Koreksi
        );

        $this->assertSame(120, $this->ledger()->onHandFromLedger($this->product->kode, $this->warehouse->id));
        $this->assertSame([], $this->ledger()->reconcile());
    }

    public function test_reconcile_reports_drift_when_the_cache_is_tampered_with(): void
    {
        $this->stockUp(100);

        // Simulate the thing the invariant forbids: writing the stock column
        // directly instead of through the ledger.
        \DB::table('stock_levels')->update(['qty_on_hand' => 999]);

        $drift = $this->ledger()->reconcile();

        $this->assertCount(1, $drift);
        $this->assertSame(999, $drift[0]['cached']);
        $this->assertSame(100, $drift[0]['ledger']);
    }

    // --- reserving ---------------------------------------------------------

    public function test_confirming_reserves_stock_without_decrementing_it(): void
    {
        $this->stockUp(100);
        $order = $this->orderFor(30);

        app(OrderStateMachine::class)->confirm($order, $this->approver());

        $level = $this->product->stockLevels()->first();

        $this->assertSame(100, $level->qty_on_hand, 'stock has not left the warehouse yet');
        $this->assertSame(30, $level->qty_reserved);
        $this->assertSame(70, $level->qtyAvailable());

        // Reserving is not a ledger event.
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_a_second_order_cannot_reserve_stock_already_held(): void
    {
        $this->stockUp(100);

        app(OrderStateMachine::class)->confirm($this->orderFor(80), $this->approver());

        $second = $this->orderFor(30);

        $this->expectException(InsufficientStockException::class);
        app(OrderStateMachine::class)->confirm($second, $this->approver());
    }

    public function test_a_failed_reservation_leaves_the_order_unconfirmed(): void
    {
        $this->stockUp(10);
        $order = $this->orderFor(30);

        try {
            app(OrderStateMachine::class)->confirm($order, $this->approver());
            $this->fail('Expected the reservation to fail.');
        } catch (InsufficientStockException $e) {
            $this->assertSame(30, $e->requested);
            $this->assertSame(10, $e->available);
        }

        $this->assertSame(OrderStatus::Submitted, $order->refresh()->status);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(0, $this->product->stockLevels()->first()->qty_reserved);
    }

    public function test_reserving_exactly_the_available_quantity_succeeds(): void
    {
        $this->stockUp(50);
        $order = $this->orderFor(50);

        app(OrderStateMachine::class)->confirm($order, $this->approver());

        $this->assertSame(0, $this->ledger()->available($this->product->kode, $this->warehouse->id));
    }

    // --- releasing ---------------------------------------------------------

    public function test_rejecting_a_confirmed_order_gives_the_stock_back(): void
    {
        $this->stockUp(100);
        $order = $this->orderFor(40);

        $machine = app(OrderStateMachine::class);
        $machine->confirm($order, $this->approver());
        $machine->reject($order, $this->approver(), 'Pelanggan membatalkan.');

        $level = $this->product->stockLevels()->first();

        $this->assertSame(100, $level->qty_on_hand);
        $this->assertSame(0, $level->qty_reserved);
        $this->assertSame(
            StockReservation::STATUS_RELEASED,
            $order->reservations()->first()->status,
        );
    }

    public function test_releasing_twice_does_not_double_credit_the_stock(): void
    {
        $this->stockUp(100);
        $order = $this->orderFor(40);

        app(OrderStateMachine::class)->confirm($order, $this->approver());

        $this->ledger()->releaseForOrder($order, 'expired');
        $this->ledger()->releaseForOrder($order, 'expired');

        $this->assertSame(0, $this->product->stockLevels()->first()->qty_reserved);
        $this->assertSame(100, $this->ledger()->available($this->product->kode, $this->warehouse->id));
    }

    public function test_an_expired_order_releases_its_reservation(): void
    {
        $this->stockUp(100);
        $order = $this->orderFor(40);

        $machine = app(OrderStateMachine::class);
        $machine->confirm($order, $this->approver());
        $machine->awaitPayment($order, $this->sales);
        $machine->expire($order);

        $this->assertSame(OrderStatus::Expired, $order->refresh()->status);
        $this->assertSame(100, $this->ledger()->available($this->product->kode, $this->warehouse->id));
    }

    // --- shipping ----------------------------------------------------------

    public function test_shipping_decrements_the_ledger_and_consumes_the_reservation(): void
    {
        $this->stockUp(100);
        $order = $this->orderFor(40);

        $machine = app(OrderStateMachine::class);
        $machine->confirm($order, $this->approver());
        $machine->awaitPayment($order, $this->sales);
        $machine->markPaid($order, meta: ['test' => true]);
        $machine->ship($order, User::factory()->warehouse()->create());

        $level = $this->product->stockLevels()->first();

        $this->assertSame(60, $level->qty_on_hand);
        $this->assertSame(0, $level->qty_reserved);
        $this->assertSame(
            StockReservation::STATUS_CONSUMED,
            $order->reservations()->first()->status,
        );

        $shipment = StockMovement::where('reason', MovementReason::Pengiriman->value)->first();
        $this->assertSame(-40, $shipment->qty_signed);
        $this->assertSame((string) $order->id, $shipment->reference_id);

        $this->assertSame([], $this->ledger()->reconcile());
    }

    // --- units -------------------------------------------------------------

    public function test_ordering_by_the_carton_reserves_base_units(): void
    {
        $this->stockUp(100);

        $company = Company::factory()->creditLimit(1_000_000_000)->create();
        $order = Order::factory()->status(OrderStatus::Submitted)->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
        ]);

        // 3 cartons of 12 = 36 pieces on the ledger.
        OrderLine::factory()->cartons(3, 12)->create([
            'order_id' => $order->id,
            'sku' => $this->product->kode,
        ]);

        app(OrderStateMachine::class)->confirm($order->refresh(), $this->approver());

        $this->assertSame(36, $this->product->stockLevels()->first()->qty_reserved);
        $this->assertSame(64, $this->ledger()->available($this->product->kode, $this->warehouse->id));
    }

    public function test_carton_conversion_is_explicit(): void
    {
        $this->assertSame(36, Unit::Ctn->toBaseQty(3, Unit::Pcs, 12));
        $this->assertSame(5, Unit::Pcs->toBaseQty(5, Unit::Pcs, 12));

        // Asking for SET of a PCS product is a bug, not a conversion.
        $this->expectException(\InvalidArgumentException::class);
        Unit::Set->toBaseQty(5, Unit::Pcs, 12);
    }

    public function test_stock_for_an_untouched_sku_is_zero_not_an_error(): void
    {
        $this->assertSame(0, $this->ledger()->available('NEVER-STOCKED', $this->warehouse->id));
    }
}
