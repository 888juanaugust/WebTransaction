<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Integrity\LedgerIntegrity;
use App\Domain\Regions\RegionContext;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Jobs\SweepLedgerIntegrity;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Region;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * The checks that existed and never ran.
 *
 * `StockLedger::reconcile()` told anyone reading it to "run it as a scheduled
 * audit"; nothing did. `InventoryValuation::reconcile()` and the control-account
 * reconciliation were the same — reachable from the test suite or from a screen
 * somebody had to think to open. `stock_levels.qty_reserved` had no check at
 * all, and is mutated with `max(0, …)` clamps that hide an over-decrement by
 * flooring it silently at zero.
 *
 * Two things are being defended. That each check actually detects the drift it
 * is named for — a guard nobody has watched fail is a guard nobody knows
 * works. And that the sweep is **per region**: average cost is kept per region,
 * so running the valuation check with the region scope open compares one
 * region's cost row against every region's movements and invents drift on a
 * healthy SKU.
 */
class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->finance = User::factory()->finance()->create();
        $this->gudang = Warehouse::factory()->create(['kode' => 'GD-HOME']);
        Product::factory()->create(['kode' => 'INT-1']);
    }

    private function integrity(): LedgerIntegrity
    {
        return app(LedgerIntegrity::class);
    }

    /**
     * Stock in, through the ledger and without a value.
     *
     * Valued movements move `product_costs` too, and this fixture posts no
     * journal behind them — so a valued receipt here would leave Persediaan
     * legitimately disagreeing with its subledger and every assertion below
     * would be reading that instead of what it is about. The book check has
     * its own test, through a path that actually posts.
     */
    private function receive(int $qty, ?Warehouse $into = null): void
    {
        app(StockLedger::class)->record(
            sku: 'INT-1',
            warehouseId: ($into ?? $this->gudang)->id,
            qtySigned: $qty,
            reason: MovementReason::Penerimaan,
            actor: $this->finance,
        );
    }

    /**
     * A reservation on a real order line: neither column is nullable, and a
     * reservation floating free of an order is not a thing the system can
     * make anyway.
     */
    private function reservation(int $qty, string $status): StockReservation
    {
        $order = Order::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'warehouse_id' => $this->gudang->id,
        ]);

        $line = OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id,
            'sku' => 'INT-1',
        ]);

        return StockReservation::query()->create([
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'sku' => 'INT-1',
            'warehouse_id' => $this->gudang->id,
            'qty_base' => $qty,
            'status' => $status,
        ]);
    }

    // --- the healthy case --------------------------------------------------

    public function test_a_system_that_only_used_its_own_domain_classes_is_clean(): void
    {
        $this->receive(10);

        $this->assertSame([], $this->integrity()->findings());
        $this->assertTrue($this->integrity()->isClean());
    }

    // --- invariant 1: the cached column against the ledger -----------------

    public function test_it_notices_a_stock_column_written_outside_the_ledger(): void
    {
        $this->receive(10);

        // Exactly what a stray UPDATE would do, bypassing StockLedger.
        DB::table('stock_levels')->where('sku', 'INT-1')->update(['qty_on_hand' => 17]);

        $findings = $this->integrity()->findings();

        $this->assertCount(1, $findings);
        $this->assertSame('stok', $findings[0]->pemeriksaan);
        $this->assertStringContainsString('INT-1', $findings[0]->subjek);
        $this->assertStringContainsString('GD-HOME', $findings[0]->subjek);
        $this->assertStringContainsString('17', $findings[0]->temuan);
        $this->assertStringContainsString('10', $findings[0]->temuan);
    }

    // --- the check that did not exist --------------------------------------

    /**
     * The reservation column's decrements are wrapped in max(0, …), so an
     * over-decrement floors at zero and leaves no trace in the column it
     * corrupted. Nothing compared it to the reservations actually held.
     */
    public function test_it_notices_a_reservation_column_that_drifted(): void
    {
        $this->receive(10);

        DB::table('stock_levels')->where('sku', 'INT-1')->update(['qty_reserved' => 4]);

        $findings = $this->integrity()->findings();

        $this->assertCount(1, $findings);
        $this->assertSame('reservasi', $findings[0]->pemeriksaan);
        $this->assertStringContainsString('4', $findings[0]->temuan);
    }

    /** A reservation held against a level row that does not exist at all. */
    public function test_it_notices_a_reservation_with_no_level_behind_it(): void
    {
        $this->reservation(6, StockReservation::STATUS_HELD);

        $findings = $this->integrity()->findings();

        $this->assertCount(1, $findings);
        $this->assertSame('reservasi', $findings[0]->pemeriksaan);
        $this->assertStringContainsString('6', $findings[0]->temuan);
    }

    public function test_a_released_reservation_is_not_counted_as_held(): void
    {
        $this->receive(10);

        $this->reservation(6, StockReservation::STATUS_RELEASED);

        // qty_reserved is 0 and the only reservation is released: agreed.
        $this->assertSame([], $this->integrity()->findings());
    }

    // --- the books ---------------------------------------------------------

    public function test_it_notices_a_control_account_that_left_its_subledger(): void
    {
        $this->receive(10);

        /*
         * A balanced manual journal that moves Persediaan without any stock
         * moving behind it — the realistic way a control account leaves its
         * subledger. Balanced on purpose, so the finding under test is the
         * control account and not the trial balance.
         */
        app(Ledger::class)->postManual(
            JournalDraft::manual('Selisih yang dibuat-buat untuk pengujian')
                ->debit(AccountCode::PERSEDIAAN, 500_000)
                ->kredit(AccountCode::MODAL_DISETOR, 500_000),
            User::factory()->owner()->create(),
        );

        $findings = $this->integrity()->findings();

        $jenis = array_map(fn ($f) => $f->pemeriksaan, $findings);
        $this->assertContains('buku', $jenis);
    }

    // --- region by region, never unpinned ----------------------------------

    /**
     * The finding that shaped the design.
     *
     * `product_costs` is unique on (region_id, sku), so cost is kept per
     * region. Two regions holding the same SKU are perfectly healthy — but
     * comparing one region's cost row against the movements of both invents
     * drift on each. The sweep pins to each region in turn precisely so this
     * cannot happen, and a check that cries wolf gets switched off long
     * before the morning it is right.
     */
    public function test_two_regions_holding_the_same_sku_are_not_reported_as_drift(): void
    {
        $this->receive(10);

        $jkt = Region::factory()->create(['kode' => 'JKT']);
        $gudangJkt = app(RegionContext::class)->within(
            $jkt, fn () => Warehouse::factory()->create(['kode' => 'GD-JKT']),
        );

        app(RegionContext::class)->within($jkt, fn () => $this->receive(20, $gudangJkt));

        $this->assertSame([], $this->integrity()->findings());
    }

    /** And a drift in the *other* region is still found. */
    public function test_a_drift_in_another_region_is_still_found(): void
    {
        $jkt = Region::factory()->create(['kode' => 'JKT']);
        $gudangJkt = app(RegionContext::class)->within(
            $jkt, fn () => Warehouse::factory()->create(['kode' => 'GD-JKT']),
        );

        app(RegionContext::class)->within($jkt, fn () => $this->receive(20, $gudangJkt));

        StockLevel::query()->withoutGlobalScope('region')
            ->where('warehouse_id', $gudangJkt->id)
            ->update(['qty_on_hand' => 99]);

        $findings = $this->integrity()->findings();

        $this->assertCount(1, $findings);
        $this->assertSame('JKT', $findings[0]->wilayah);
        $this->assertStringContainsString('GD-JKT', $findings[0]->subjek);
    }

    // --- the sweep ---------------------------------------------------------

    public function test_the_nightly_sweep_tells_the_owner_and_repairs_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $this->receive(10);

        DB::table('stock_levels')->where('sku', 'INT-1')->update(['qty_on_hand' => 17]);

        app(SweepLedgerIntegrity::class)->handle($this->integrity());

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(
            (string) $owner->id,
            (string) DB::table('notifications')->value('notifiable_id'),
        );

        /*
         * And the drift is still there. Rebuilding the cache would make the
         * symptom vanish and leave whatever wrote outside the domain to do it
         * again next week, unwitnessed.
         */
        $this->assertSame(17, (int) DB::table('stock_levels')->where('sku', 'INT-1')->value('qty_on_hand'));
        $this->assertCount(1, $this->integrity()->findings());
    }

    public function test_the_sweep_says_nothing_when_the_books_agree(): void
    {
        User::factory()->owner()->create();
        $this->receive(10);

        app(SweepLedgerIntegrity::class)->handle($this->integrity());

        $this->assertDatabaseCount('notifications', 0);
    }

    // --- the command -------------------------------------------------------

    public function test_the_command_exits_zero_when_clean_and_one_when_not(): void
    {
        $this->receive(10);

        $this->artisan('integritas:periksa')->assertExitCode(0);

        DB::table('stock_levels')->where('sku', 'INT-1')->update(['qty_on_hand' => 17]);

        $this->artisan('integritas:periksa')->assertExitCode(1);
    }

    protected function tearDown(): void
    {
        NotificationFacade::clearResolvedInstances();

        parent::tearDown();
    }
}
