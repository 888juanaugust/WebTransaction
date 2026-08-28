<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Cart\CartService;
use App\Domain\Credit\DebtAging;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Regions\RegionContext;
use App\Domain\Uom\Unit;
use App\Jobs\SweepDebtAging;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The credit-sales transaction flow, end to end.
 *
 * Pending waits for the marketing in charge; acceptance is the credit
 * decision; the goods ship before the money arrives; and "finished" means
 * paid. Each of those is a rule somebody could quietly widen, so each is
 * pinned here — including the boundaries of the 150-day freeze, where an
 * off-by-one locks a customer a day early on the owner's own stated terms.
 */
class TransactionFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $sales;

    private User $marketing;

    private Company $pelanggan;

    private Warehouse $gudang;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $region = $this->currentRegion();
        $this->owner = User::factory()->owner()->create();
        $this->sales = User::factory()->sales()->create(['region_id' => $region->id]);
        $this->marketing = User::factory()->marketing()->create(['region_id' => $region->id]);

        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $this->gudang = Warehouse::factory()->create();

        $assigner = app(TeamAssigner::class);
        $assigner->assignSales($this->pelanggan, $this->sales, $this->owner);
        $assigner->assignMarketing($this->pelanggan, $this->marketing, $this->owner);
    }

    private function machine(): OrderStateMachine
    {
        return app(OrderStateMachine::class);
    }

    private function draftOrder(int $qty = 5): Order
    {
        $product = Product::factory()->create(['qty_per_ctn' => 10]);

        StockLevel::query()->create([
            'sku' => $product->kode,
            'warehouse_id' => $this->gudang->id,
            'qty_on_hand' => 500,
            'qty_reserved' => 0,
        ]);

        $version = PriceListVersion::factory()->published()->create();
        PriceListItem::factory()->create([
            'version_id' => $version->id,
            'kode' => $product->kode,
            'harga' => 100_000,
        ]);

        $order = Order::factory()->status(OrderStatus::Draft)->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $this->gudang->id,
        ]);

        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id,
            'sku' => $product->kode,
        ]);

        return $order;
    }

    // ---------------------------------------------------------------- seat

    public function test_the_salesperson_cannot_approve_the_order_they_submitted(): void
    {
        /*
         * The reorganisation's own hard rule: whoever is paid on the sale
         * must not approve its credit.
         */
        $order = $this->draftOrder();
        $this->machine()->submit($order, $this->sales);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/keputusan kredit/');

        $this->machine()->confirm($order->refresh(), $this->sales);
    }

    public function test_a_marketing_not_in_charge_cannot_approve(): void
    {
        $lain = User::factory()->marketing()->create(['region_id' => $this->currentRegion()->id]);

        $order = $this->draftOrder();
        $this->machine()->submit($order, $this->sales);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/marketing lain/');

        $this->machine()->confirm($order->refresh(), $lain);
    }

    public function test_the_assigned_marketing_approves_and_that_reserves_stock(): void
    {
        $order = $this->draftOrder();
        $this->machine()->submit($order, $this->sales);

        $this->machine()->confirm($order->refresh(), $this->marketing);

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
        $this->assertGreaterThan(0, (int) StockLevel::query()->sum('qty_reserved'));
    }

    public function test_a_customer_with_no_marketing_waits_for_the_owner(): void
    {
        app(TeamAssigner::class)->assignMarketing($this->pelanggan, null, $this->owner);

        $order = $this->draftOrder();
        $this->machine()->submit($order, $this->sales);

        try {
            $this->machine()->confirm($order->refresh(), $this->marketing);
            $this->fail('A marketing approved a customer that has no assigned marketing.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('belum punya marketing', $e->getMessage());
        }

        // The Owner is the escape hatch, so the order is not stuck forever.
        $this->machine()->confirm($order->refresh(), $this->owner);
        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);
    }

    public function test_marketing_submitting_for_their_own_customer_approves_in_the_same_breath(): void
    {
        $order = $this->draftOrder();

        $hasil = $this->machine()->submitAndMaybeApprove($order, $this->marketing);

        $this->assertSame(OrderStatus::Confirmed, $hasil->status);

        // Two logged events — submit then confirm — so the history reads the
        // same as any other order's.
        $this->assertSame(
            [OrderStatus::Submitted, OrderStatus::Confirmed],
            $order->events()->orderBy('id')->get()->pluck('to_status')->all(),
        );
    }

    public function test_sales_submitting_through_the_same_path_stays_pending(): void
    {
        $order = $this->draftOrder();

        $hasil = $this->machine()->submitAndMaybeApprove($order, $this->sales);

        $this->assertSame(OrderStatus::Submitted, $hasil->status);
    }

    // ---------------------------------------------------------------- credit shipping

    public function test_goods_ship_on_credit_and_settlement_finishes_the_order(): void
    {
        /*
         * The ordinary credit path now: approve → invoice → ship → the debt
         * stands → the money arrives on terms → finished. Nobody clicks
         * "selesai"; the last rupiah does.
         */
        $order = $this->draftOrder();
        $this->machine()->submit($order, $this->sales);
        $this->machine()->confirm($order->refresh(), $this->marketing);
        $this->machine()->awaitPayment($order->refresh());

        $gudangStaf = User::factory()->warehouse()->create();
        $this->machine()->ship($order->refresh(), $gudangStaf);

        $this->assertSame(OrderStatus::Shipped, $order->refresh()->status);

        $invoice = $order->refresh()->invoice;
        $this->assertSame(Invoice::STATUS_OPEN, $invoice->status);

        // The customer pays, months later in real life.
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: $invoice->total_rupiah,
            actor: User::factory()->finance()->create(),
            invoice: $invoice,
        );

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // The completing transition was the money's, not a person's.
        $akhir = $order->events()->orderBy('id')->get()->last();
        $this->assertSame(OrderStatus::Completed, $akhir->to_status);
        $this->assertNull($akhir->actor_id);
    }

    public function test_the_prepay_path_still_works(): void
    {
        $order = $this->draftOrder();
        $this->machine()->submit($order, $this->sales);
        $this->machine()->confirm($order->refresh(), $this->marketing);
        $this->machine()->awaitPayment($order->refresh());

        $invoice = $order->refresh()->invoice;

        app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: $invoice->total_rupiah,
            actor: User::factory()->finance()->create(),
            invoice: $invoice,
        );

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);

        $this->machine()->ship($order->refresh(), User::factory()->warehouse()->create());
        $this->assertSame(OrderStatus::Shipped, $order->refresh()->status);
    }

    public function test_a_global_marketing_approves_across_regions_and_the_writes_land_in_the_customers_books(): void
    {
        /*
         * Marketing carries no region. They read open-to-all — the state
         * that refuses creates — so the state machine must pin itself to
         * the order's region for the reservation and the events to have
         * books to land in. This is the write path the middleware change
         * depends on.
         */
        $order = $this->draftOrder();
        $this->machine()->submit($order, $this->sales);

        app(RegionContext::class)->openToAll();

        try {
            $this->machine()->confirm($order->refresh(), $this->marketing);
        } finally {
            $this->pinToDefaultRegion();
        }

        $this->assertSame(OrderStatus::Confirmed, $order->refresh()->status);

        $reservation = DB::table('stock_reservations')->where('order_id', $order->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame($order->region_id, $reservation->region_id);
    }

    // ---------------------------------------------------------------- aging

    public function test_the_sweep_notifies_the_team_once_per_invoice(): void
    {
        Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $this->pelanggan->id,
            'issued_on' => today()->subDays(120),
            'due_date' => today()->subDays(90),
        ]);

        (new SweepDebtAging)->handle(app(DebtAging::class));

        // Both seats got the panel notification.
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->sales->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->marketing->id)->count());

        // A second run finds nothing: the mark is the claim.
        (new SweepDebtAging)->handle(app(DebtAging::class));

        $this->assertSame(2, DB::table('notifications')->count());
    }

    public function test_a_late_seen_debt_already_past_the_freeze_is_told_the_truth(): void
    {
        /*
         * The sweep normally meets an invoice the night it turns three
         * months, and warns "one month until locked". The first sweep after
         * a team is assigned late, or after a backdated faktur, meets an
         * invoice already past four — the warning must say the lock is
         * already on, not promise a month that is gone. Found in the
         * browser: an already-frozen invoice produced "satu bulan lagi".
         */
        Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $this->pelanggan->id,
            'issued_on' => today()->subDays(160),
            'due_date' => today()->subDays(130),
        ]);

        (new SweepDebtAging)->handle(app(DebtAging::class));

        $badan = DB::table('notifications')
            ->where('notifiable_id', $this->marketing->id)
            ->value('data');

        $this->assertStringContainsString('sudah terkunci', $badan);
        $this->assertStringNotContainsString('hari lagi pelanggan', $badan);
    }

    public function test_a_debt_one_day_short_of_120_days_is_not_flagged(): void
    {
        Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $this->pelanggan->id,
            'issued_on' => today()->subDays(119),
            'due_date' => today()->subDays(89),
        ]);

        (new SweepDebtAging)->handle(app(DebtAging::class));

        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_a_paid_invoice_never_ages(): void
    {
        Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $this->pelanggan->id,
            'status' => Invoice::STATUS_PAID,
            'issued_on' => today()->subDays(200),
            'due_date' => today()->subDays(170),
        ]);

        $this->assertFalse(app(DebtAging::class)->isFrozen($this->pelanggan));

        (new SweepDebtAging)->handle(app(DebtAging::class));
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_a_frozen_customer_cannot_check_out_from_the_portal(): void
    {
        Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $this->pelanggan->id,
            'issued_on' => today()->subDays(151),
            'due_date' => today()->subDays(121),
        ]);

        $pembeli = CustomerUser::factory()->create(['company_id' => $this->pelanggan->id]);
        $cart = app(CartService::class);

        $product = Product::factory()->create(['qty_per_ctn' => 10]);
        $cart->add($pembeli, $product->kode, Unit::Pcs, 5);

        try {
            $cart->checkout($pembeli);
            $this->fail('A frozen customer checked out.');
        } catch (DomainException $e) {
            // The message names the invoice, because "you are blocked"
            // without "pay this" is a support call that starts angry.
            $this->assertStringContainsString('terkunci', $e->getMessage());
            $this->assertStringContainsString('INV', $e->getMessage());
        }

        $this->assertSame(0, Order::query()->where('company_id', $this->pelanggan->id)
            ->where('status', '!=', OrderStatus::Draft)->count());
    }

    public function test_paying_the_aged_invoice_unfreezes_without_anybody_doing_anything(): void
    {
        /*
         * Fall-due is derived, never stored — so the freeze lifts the moment
         * the money lands, with no flag for anyone to forget to clear.
         */
        $faktur = Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $this->pelanggan->id,
            'issued_on' => today()->subDays(160),
            'due_date' => today()->subDays(130),
        ]);

        $aging = app(DebtAging::class);
        $this->assertTrue($aging->isFrozen($this->pelanggan));

        app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: 2_000_000,
            actor: User::factory()->finance()->create(),
            invoice: $faktur,
        );

        $this->assertFalse($aging->isFrozen($this->pelanggan));
    }
}
