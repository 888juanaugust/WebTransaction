<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Credit\CollectionDesk;
use App\Domain\Credit\CreditChecker;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Regions\RegionContext;
use App\Models\Company;
use App\Models\Invoice;
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
 * A customer's credit, when their orders book in more than one region.
 *
 * Since the multi-warehouse split, one order can become several transactions
 * in several regions' books, and CLAUDE.md is explicit that a customer's
 * exposure aggregates across regions. `committed()` did lift the region scope
 * — on the orders. The `whereDoesntHave('invoice')` beside it stayed scoped,
 * so a piece booked and invoiced in Jakarta looked *uninvoiced* from Surabaya
 * and was counted as a committed order on top of its own outstanding invoice.
 *
 * The measured cost: a customer owing Rp 11.100.000 showed Rp 15.540.000 of
 * exposure. Forty per cent of a credit limit eaten twice, and eaten hardest by
 * the customers ordering enough to clear a warehouse — the ones a wholesaler
 * least wants to refuse.
 *
 * The general rule, which is what these tests really defend: when a read
 * deliberately crosses regions, every relation it asks about has to cross with
 * it. A scope lifted at the top and left in place one level down does not
 * fail — it quietly answers a different question.
 */
class CreditAcrossRegionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $sales;

    private User $marketing;

    private User $finance;

    private Company $pelanggan;

    private Warehouse $gudang;

    private Warehouse $gudangJkt;

    private Region $jkt;

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

        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $assigner = app(TeamAssigner::class);
        $assigner->assignSales($this->pelanggan, $this->sales, $this->owner);
        $assigner->assignMarketing($this->pelanggan, $this->marketing, $this->owner);

        Product::factory()->create(['kode' => 'CR-1', 'qty_per_ctn' => 10]);
        $version = PriceListVersion::query()->where('status', 'published')->first()
            ?? PriceListVersion::factory()->published()->create();
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => 'CR-1', 'harga' => 100_000,
        ]);
    }

    private function stockAt(Warehouse $gudang, int $qty): void
    {
        app(RegionContext::class)->within((int) $gudang->region_id, fn () => StockLevel::query()->create([
            'sku' => 'CR-1', 'warehouse_id' => $gudang->id, 'qty_on_hand' => $qty, 'qty_reserved' => 0,
        ]));
    }

    /** An order big enough that its goods come from both regions. */
    private function splitOrder(int $qty = 100): Order
    {
        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $this->gudang->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => 'CR-1', 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order, $this->sales);
        $machine->confirm($order->refresh(), $this->marketing);

        return $order->refresh();
    }

    // --- the defect --------------------------------------------------------

    public function test_a_piece_invoiced_in_another_region_is_not_counted_twice(): void
    {
        $this->stockAt($this->gudang, 60);
        $this->stockAt($this->gudangJkt, 100);

        $order = $this->splitOrder();
        $sibling = $order->splitChildren()->sole();

        $this->assertNotSame((int) $order->region_id, (int) $sibling->region_id);

        // Both pieces billed, each in its own region's books.
        $machine = app(OrderStateMachine::class);
        $machine->awaitPayment($order->refresh(), $this->finance);
        app(RegionContext::class)->within(
            $this->jkt, fn () => $machine->awaitPayment($sibling->refresh(), $this->finance),
        );

        $ditagih = (int) Order::query()->withoutGlobalScope('region')
            ->where('company_id', $this->pelanggan->id)
            ->sum('total_rupiah');

        $status = app(CreditChecker::class)->status($this->pelanggan->refresh());

        // Everything is on an invoice now, so nothing is merely committed.
        $this->assertSame(0, $status->committed);
        $this->assertSame($ditagih, $status->outstanding);
        $this->assertSame($ditagih, app(OutstandingReceivables::class)->exposureFor($this->pelanggan));

        // The whole point: exposure is what they owe, not more.
        $this->assertSame(
            $this->pelanggan->credit_limit_rupiah - $ditagih,
            $status->available(),
        );
    }

    /**
     * The other half of the same question: a piece that genuinely has no
     * invoice yet must still count against the limit, wherever it booked.
     * Fixing the double count by ignoring foreign pieces would swap an
     * overstatement for a hole.
     */
    public function test_an_uninvoiced_piece_in_another_region_still_commits_credit(): void
    {
        $this->stockAt($this->gudang, 60);
        $this->stockAt($this->gudangJkt, 100);

        $order = $this->splitOrder();
        $sibling = $order->splitChildren()->sole();

        // Only the home piece is billed; the Jakarta one is confirmed and
        // waiting.
        app(OrderStateMachine::class)->awaitPayment($order->refresh(), $this->finance);

        $status = app(CreditChecker::class)->status($this->pelanggan->refresh());

        $this->assertSame((int) $sibling->refresh()->total_rupiah, $status->committed);
        $this->assertSame((int) $order->refresh()->total_rupiah, $status->outstanding);
    }

    /** Nothing about the ordinary single-region case moves. */
    public function test_one_region_is_unchanged(): void
    {
        $this->stockAt($this->gudang, 200);

        $order = $this->splitOrder(50);

        $this->assertSame(0, $order->splitChildren()->count());

        $status = app(CreditChecker::class)->status($this->pelanggan->refresh());

        $this->assertSame((int) $order->total_rupiah, $status->committed);
        $this->assertSame(0, $status->outstanding);
    }

    /**
     * Re-checking an order already confirmed must not count it against itself
     * — the guard that lets approval be idempotent. Worth pinning here too,
     * since the exclusion and the invoice sub-query sit in the same method.
     */
    public function test_re_checking_an_order_does_not_count_it_against_itself(): void
    {
        $this->stockAt($this->gudang, 200);

        $order = $this->splitOrder(50);

        $status = app(CreditChecker::class)->check($order->refresh());

        $this->assertSame(0, $status->committed);
        $this->assertSame((int) $order->total_rupiah, $status->orderAmount);
    }

    // --- the same blind spot, in the collections desk ----------------------

    /**
     * The team chases the customer, not the region.
     *
     * Penagihan read invoices region-scoped, so a faktur booked in Jakarta
     * after a split was invisible to the sales and marketing who hold that
     * customer — two overdue fakturs for one debtor, one of them shown, and
     * the missing one ageing quietly while everybody believed the list was
     * the list.
     */
    public function test_a_seat_chases_their_customers_debt_in_every_region(): void
    {
        $this->stockAt($this->gudang, 60);
        $this->stockAt($this->gudangJkt, 100);

        $order = $this->splitOrder();
        $sibling = $order->splitChildren()->sole();

        $machine = app(OrderStateMachine::class);
        $machine->awaitPayment($order->refresh(), $this->finance);
        app(RegionContext::class)->within(
            $this->jkt, fn () => $machine->awaitPayment($sibling->refresh(), $this->finance),
        );

        Invoice::query()->withoutGlobalScope('region')
            ->update(['due_date' => now()->subDays(40)->toDateString()]);

        $desk = app(CollectionDesk::class);

        foreach ([$this->sales, $this->marketing] as $seat) {
            $this->assertCount(
                2,
                $desk->chaseable($seat)->get(),
                "{$seat->role()->value} must see both of their customer's overdue fakturs",
            );
        }
    }

    /**
     * The other branch stays scoped, and that is not an oversight. "Everything
     * outstanding" is a region-wide total rather than a question about one
     * customer, and CLAUDE.md keeps those inside their own books.
     */
    public function test_the_region_wide_view_stays_inside_its_own_books(): void
    {
        $this->stockAt($this->gudang, 60);
        $this->stockAt($this->gudangJkt, 100);

        $order = $this->splitOrder();
        $sibling = $order->splitChildren()->sole();

        $machine = app(OrderStateMachine::class);
        $machine->awaitPayment($order->refresh(), $this->finance);
        app(RegionContext::class)->within(
            $this->jkt, fn () => $machine->awaitPayment($sibling->refresh(), $this->finance),
        );

        Invoice::query()->withoutGlobalScope('region')
            ->update(['due_date' => now()->subDays(40)->toDateString()]);

        $this->assertCount(1, app(CollectionDesk::class)->chaseable($this->finance)->get());
    }
}
