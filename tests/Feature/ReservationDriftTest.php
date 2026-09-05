<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Regions\RegionContext;
use App\Domain\Stock\StockLedger;
use App\Jobs\ReleaseStaleReservations;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Region;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `qty_reserved` comes back to the truth after every lifecycle.
 *
 * That column is not summed from anything: it is moved by `+=` and `-=` as
 * orders are confirmed, shipped, rejected, expired and swept, and both
 * decrements are wrapped in `max(0, …)` — which floors an over-decrement
 * silently at zero rather than letting it go negative. Until
 * `reconcileReservations()` existed nothing compared it to the reservations
 * actually held, so a mistake in any of those paths left no trace in the
 * column it corrupted.
 *
 * Nothing here is a bug report: every path below already reconciles. It is
 * kept because the check is new, and a walk through each lifecycle asserting
 * the column still agrees is what catches the refactor that breaks one of
 * them. Over-reserved is stock nobody can sell and nobody can explain;
 * under-reserved is selling the same carton twice.
 */
class ReservationDriftTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $sales;

    private User $marketing;

    private User $finance;

    private User $gudangUser;

    private Company $pelanggan;

    private Warehouse $gudang;

    private Region $jkt;

    private Warehouse $gudangJkt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountsSeeder::class);

        $region = $this->currentRegion();
        $this->jkt = Region::factory()->create(['kode' => 'JKT']);
        $this->gudang = Warehouse::factory()->create(['kode' => 'GD-HOME']);
        $this->gudangJkt = app(RegionContext::class)->within(
            $this->jkt, fn () => Warehouse::factory()->create(['kode' => 'GD-JKT']),
        );

        $this->owner = User::factory()->owner()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $region->id]);
        $this->marketing = User::factory()->marketing()->create(['region_id' => null]);
        $this->finance = User::factory()->finance()->create();
        $this->gudangUser = User::factory()->warehouse()->create();

        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $a = app(TeamAssigner::class);
        $a->assignSales($this->pelanggan, $this->sales, $this->owner);
        $a->assignMarketing($this->pelanggan, $this->marketing, $this->owner);
    }

    private function priced(string $kode, int $harga = 100_000): void
    {
        Product::factory()->create(['kode' => $kode, 'qty_per_ctn' => 10]);
        $v = PriceListVersion::query()->where('status', 'published')->first()
            ?? PriceListVersion::factory()->published()->create();
        PriceListItem::factory()->create(['version_id' => $v->id, 'kode' => $kode, 'harga' => $harga]);
    }

    private function stockAt(Warehouse $g, string $sku, int $qty): void
    {
        app(RegionContext::class)->within((int) $g->region_id, fn () => StockLevel::query()->create([
            'sku' => $sku, 'warehouse_id' => $g->id, 'qty_on_hand' => $qty, 'qty_reserved' => 0,
        ]));
    }

    private function submitted(string $sku, int $qty): Order
    {
        $o = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $this->gudang->id,
        ]);
        OrderLine::factory()->qty($qty)->create(['order_id' => $o->id, 'sku' => $sku, 'urutan' => 1]);
        app(OrderStateMachine::class)->submit($o, $this->sales);

        return $o->refresh();
    }

    /**
     * Region by region, the way LedgerIntegrity sweeps — never unpinned,
     * since both sides of the comparison are scoped and lifting one alone
     * asks a different question.
     */
    private function assertReservationsAgree(string $tahap): void
    {
        $drift = app(RegionContext::class)->acrossAll(function () {
            $out = [];

            foreach (Region::query()->orderBy('id')->get() as $region) {
                $out = array_merge($out, app(RegionContext::class)->within(
                    $region, fn () => app(StockLedger::class)->reconcileReservations(),
                ));
            }

            return $out;
        });

        $this->assertSame([], $drift, "stok dipesan tidak cocok {$tahap}");
    }

    public function test_every_lifecycle_leaves_the_reservation_column_agreeing(): void
    {
        $m = app(OrderStateMachine::class);

        // 1. confirm then ship
        $this->priced('R-1');
        $this->stockAt($this->gudang, 'R-1', 100);
        $o = $this->submitted('R-1', 10);
        $m->confirm($o, $this->marketing);
        $this->assertReservationsAgree('setelah konfirmasi');
        $m->awaitPayment($o->refresh(), $this->finance);
        $m->markPaid($o->refresh(), ['source' => 'test']);
        $m->ship($o->refresh(), $this->gudangUser);
        $this->assertReservationsAgree('setelah kirim');

        // 2. confirm then reject
        $this->priced('R-2');
        $this->stockAt($this->gudang, 'R-2', 100);
        $o2 = $this->submitted('R-2', 10);
        $m->confirm($o2, $this->marketing);
        $m->reject($o2->refresh(), $this->marketing, 'Batal');
        $this->assertReservationsAgree('setelah tolak');

        // 3. confirm then expire
        $this->priced('R-3');
        $this->stockAt($this->gudang, 'R-3', 100);
        $o3 = $this->submitted('R-3', 10);
        $m->confirm($o3, $this->marketing);
        $m->awaitPayment($o3->refresh(), $this->finance);
        $m->expire($o3->refresh(), 'Kedaluwarsa');
        $this->assertReservationsAgree('setelah kedaluwarsa');

        // 4. split across regions, then ship both pieces
        $this->priced('R-4');
        $this->stockAt($this->gudang, 'R-4', 60);
        $this->stockAt($this->gudangJkt, 'R-4', 100);
        $o4 = $this->submitted('R-4', 100);
        $m->confirm($o4, $this->marketing);
        $this->assertReservationsAgree('setelah konfirmasi pecahan');

        foreach ([$o4->refresh(), $o4->splitChildren()->sole()] as $piece) {
            $m->awaitPayment($piece->refresh(), $this->finance);
            $m->markPaid($piece->refresh(), ['source' => 'test']);
            $m->ship($piece->refresh(), $this->gudangUser);
        }
        $this->assertReservationsAgree('setelah kirim pecahan');

        // 5. stale reservation sweep
        $this->priced('R-5');
        $this->stockAt($this->gudang, 'R-5', 100);
        $o5 = $this->submitted('R-5', 10);
        $m->confirm($o5, $this->marketing);
        $o5->refresh()->forceFill(['reservation_expires_at' => now()->subDay()])->save();
        (new ReleaseStaleReservations)->handle($m);
        $this->assertReservationsAgree('setelah sapuan reservasi basi');

        /*
         * And the sweep actually did something, so the assertion above is not
         * passing because nothing happened. A *confirmed* order whose
         * reservation went stale is rejected rather than expired — it was
         * never billed, so there is nothing to expire — which is the job's
         * own distinction, pinned here because this test would otherwise be
         * happy with a sweep that swept nothing.
         */
        $this->assertSame(OrderStatus::Rejected, $o5->refresh()->status);
    }
}
