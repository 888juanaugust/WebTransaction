<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Orders\CreditLimitExceededException;
use App\Domain\Orders\OrderSplitter;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Regions\RegionContext;
use App\Domain\Stock\InsufficientStockException;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Region;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The multi-warehouse split: goods scattered across regions become X
 * transactions at approval — home region drained first, each piece booked
 * in its shipping warehouse's region, all-or-nothing.
 */
class OrderSplitTest extends TestCase
{
    use RefreshDatabase;

    private Region $jkt;

    private Warehouse $gudangHome;

    private Warehouse $gudangJkt;

    private User $owner;

    private User $sales;

    private User $marketing;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $region = $this->currentRegion();
        $this->jkt = Region::factory()->create(['kode' => 'JKT']);

        $this->gudangHome = Warehouse::factory()->create(['kode' => 'GD-HOME']);
        $this->gudangJkt = app(RegionContext::class)->within(
            $this->jkt,
            fn () => Warehouse::factory()->create(['kode' => 'GD-JKT']),
        );

        $this->owner = User::factory()->owner()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $region->id]);
        $this->marketing = User::factory()->marketing()->create(['region_id' => null]);

        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $assigner = app(TeamAssigner::class);
        $assigner->assignSales($this->pelanggan, $this->sales, $this->owner);
        $assigner->assignMarketing($this->pelanggan, $this->marketing, $this->owner);
    }

    private function productPriced(string $kode, int $harga = 100_000): Product
    {
        $product = Product::factory()->create(['kode' => $kode, 'qty_per_ctn' => 10]);

        $version = PriceListVersion::query()->where('status', 'published')->first()
            ?? PriceListVersion::factory()->published()->create();

        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => $kode, 'harga' => $harga,
        ]);

        return $product;
    }

    private function stockAt(Warehouse $gudang, string $sku, int $qty): void
    {
        app(RegionContext::class)->within((int) $gudang->region_id, fn () => StockLevel::query()->create([
            'sku' => $sku, 'warehouse_id' => $gudang->id,
            'qty_on_hand' => $qty, 'qty_reserved' => 0,
        ]));
    }

    private function submittedOrder(array $lines): Order
    {
        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $this->gudangHome->id,
        ]);

        foreach ($lines as $i => [$sku, $qty]) {
            OrderLine::factory()->qty($qty)->create([
                'order_id' => $order->id, 'sku' => $sku, 'urutan' => $i + 1,
            ]);
        }

        app(OrderStateMachine::class)->submit($order, $this->sales);

        return $order->refresh();
    }

    public function test_everything_at_home_confirms_without_splitting(): void
    {
        $this->productPriced('SPL-A');
        $this->stockAt($this->gudangHome, 'SPL-A', 100);

        $order = $this->submittedOrder([['SPL-A', 50]]);
        app(OrderStateMachine::class)->confirm($order, $this->marketing);

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
        $this->assertSame(0, $order->splitChildren()->count());
    }

    public function test_a_scattered_order_splits_with_home_region_drained_first(): void
    {
        $this->productPriced('SPL-B');
        $this->stockAt($this->gudangHome, 'SPL-B', 60);
        $this->stockAt($this->gudangJkt, 'SPL-B', 100);

        $order = $this->submittedOrder([['SPL-B', 100]]);
        app(OrderStateMachine::class)->confirm($order, $this->marketing);

        // The parent keeps home's 60, confirmed in the home books.
        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(60, (int) $order->lines()->sum('qty_base'));
        $this->assertSame($this->currentRegion()->id, (int) $order->region_id);

        // One sibling carries the remaining 40 — in Jakarta's books, with
        // Jakarta's document number, reserved at Jakarta's warehouse.
        $sibling = $order->splitChildren()->sole();
        $this->assertSame(OrderStatus::Confirmed, $sibling->status);
        $this->assertSame(40, (int) $sibling->lines()->sum('qty_base'));
        $this->assertSame($this->jkt->id, (int) $sibling->region_id);
        $this->assertStringContainsString('-JKT-', $sibling->nomor);
        $this->assertSame($this->gudangJkt->id, (int) $sibling->warehouse_id);

        $reservation = StockReservation::query()
            ->withoutGlobalScope('region')
            ->where('order_id', $sibling->id)
            ->sole();
        $this->assertSame($this->gudangJkt->id, (int) $reservation->warehouse_id);

        // Both pieces committed against one credit line.
        $committed = (int) Order::query()->withoutGlobalScope('region')
            ->where('company_id', $this->pelanggan->id)
            ->where('status', OrderStatus::Confirmed)
            ->sum('total_rupiah');
        $this->assertGreaterThan(0, $committed);
        $this->assertSame($committed, (int) $order->total_rupiah + (int) $sibling->total_rupiah);
    }

    public function test_a_line_the_home_warehouse_cannot_touch_moves_wholly_to_the_sibling(): void
    {
        $this->productPriced('SPL-C');
        $this->productPriced('SPL-D');
        $this->stockAt($this->gudangHome, 'SPL-C', 100);
        $this->stockAt($this->gudangJkt, 'SPL-D', 100);

        $order = $this->submittedOrder([['SPL-C', 10], ['SPL-D', 20]]);
        app(OrderStateMachine::class)->confirm($order, $this->marketing);

        $this->assertSame(['SPL-C'], $order->refresh()->lines()->pluck('sku')->all());

        $sibling = $order->splitChildren()->sole();
        $this->assertSame(['SPL-D'], $sibling->lines()->pluck('sku')->all());
        $this->assertSame(20, (int) $sibling->lines()->sum('qty_base'));
    }

    public function test_an_order_whose_home_holds_nothing_is_rehomed_not_split(): void
    {
        $this->productPriced('SPL-E');
        $this->stockAt($this->gudangJkt, 'SPL-E', 100);

        $order = $this->submittedOrder([['SPL-E', 30]]);
        $nomorLama = $order->nomor;

        app(OrderStateMachine::class)->confirm($order, $this->marketing);

        // No sibling: one warehouse ships it all, so it stays one
        // transaction — re-homed into the region whose goods they are.
        $order = Order::query()->withoutGlobalScope('region')->find($order->id);
        $this->assertSame(0, $order->splitChildren()->count());
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($this->jkt->id, (int) $order->region_id);
        $this->assertSame($this->gudangJkt->id, (int) $order->warehouse_id);
        $this->assertNotSame($nomorLama, $order->nomor);
        $this->assertStringContainsString('-JKT-', $order->nomor);
    }

    public function test_insufficient_everywhere_refuses_and_leaves_the_order_untouched(): void
    {
        $this->productPriced('SPL-F');
        $this->stockAt($this->gudangHome, 'SPL-F', 10);
        $this->stockAt($this->gudangJkt, 'SPL-F', 15);

        $order = $this->submittedOrder([['SPL-F', 100]]);

        try {
            app(OrderStateMachine::class)->confirm($order, $this->marketing);
            $this->fail('An order no warehouse can cover was confirmed.');
        } catch (InsufficientStockException $e) {
            $this->assertStringContainsString('SPL-F', $e->getMessage());
        }

        $this->assertSame(OrderStatus::Submitted, $order->refresh()->status);
        $this->assertSame(0, $order->splitChildren()->count());
        $this->assertSame(0, StockReservation::query()->withoutGlobalScope('region')->count());
    }

    public function test_a_split_over_the_credit_limit_rolls_the_whole_thing_back(): void
    {
        /*
         * The all-or-nothing rule: if the last sibling fails the credit
         * check, the earlier pieces must not stay confirmed — a customer
         * either gets the whole order or keeps waiting.
         */
        $this->productPriced('SPL-G', harga: 1_000_000);
        $this->stockAt($this->gudangHome, 'SPL-G', 60);
        $this->stockAt($this->gudangJkt, 'SPL-G', 100);

        $this->pelanggan->forceFill(['credit_limit_rupiah' => 70_000_000])->save();

        $order = $this->submittedOrder([['SPL-G', 100]]);

        try {
            app(OrderStateMachine::class)->confirm($order, $this->marketing);
            $this->fail('A split past the limit was confirmed.');
        } catch (CreditLimitExceededException) {
            // expected
        }

        $this->assertSame(OrderStatus::Submitted, $order->refresh()->status);
        $this->assertSame(100, (int) $order->lines()->sum('qty_base'));
        $this->assertSame(0, $order->splitChildren()->count());
        $this->assertSame(0, StockReservation::query()->withoutGlobalScope('region')->count());
    }

    public function test_the_planner_prefers_the_fuller_foreign_warehouse_for_the_remainder(): void
    {
        $tiga = Region::factory()->create(['kode' => 'BDG']);
        $gudangBdg = app(RegionContext::class)->within(
            $tiga,
            fn () => Warehouse::factory()->create(['kode' => 'GD-BDG']),
        );

        $this->productPriced('SPL-H');
        $this->stockAt($this->gudangJkt, 'SPL-H', 20);
        $this->stockAt($gudangBdg, 'SPL-H', 80);

        $order = $this->submittedOrder([['SPL-H', 50]]);

        $plan = app(OrderSplitter::class)->plan($order);

        // Home holds nothing; Bandung holds the most, so the whole line
        // ships from one place instead of two.
        $this->assertSame([$gudangBdg->id], array_keys($plan));
    }
}
