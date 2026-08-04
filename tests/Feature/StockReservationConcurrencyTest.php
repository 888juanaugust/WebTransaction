<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\InsufficientStockException;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The reservation lock, tested with real concurrency.
 *
 * `SELECT ... FOR UPDATE` inside the confirming transaction is what stops two
 * staff selling the same last carton. Every other test in this suite runs
 * single-threaded, which cannot distinguish a working lock from no lock at
 * all — so these fork actual processes and let them race.
 *
 * DatabaseMigrations rather than RefreshDatabase: RefreshDatabase wraps each
 * test in a transaction that is never committed, and a forked child on its own
 * connection would see an empty database.
 */
#[Group('concurrency')]
class StockReservationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const SKU = 'YH-LAST';

    private Warehouse $warehouse;

    private User $sales;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create();
        $this->sales = User::factory()->sales()->create();
        $this->company = Company::factory()->creditLimit(9_000_000_000)->create();

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 12]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);
    }

    private function stockUp(int $qty): void
    {
        app(StockLedger::class)->record(self::SKU, $this->warehouse->id, $qty, MovementReason::Penerimaan);
    }

    /** An order for exactly `$qty` base units, sitting at submitted. */
    private function submittedOrderFor(int $qty, int $n): Order
    {
        $order = Order::factory()->create([
            'nomor' => "SO-RACE-{$n}",
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->sales->id,
        ]);

        OrderLine::factory()->qty($qty)->create(['order_id' => $order->id, 'sku' => self::SKU]);

        app(OrderStateMachine::class)->submit($order->refresh(), $this->sales);

        return $order->refresh();
    }

    // --- the lock itself ----------------------------------------------------

    /**
     * Proves the row lock exists, rather than only that the arithmetic is
     * right. A second connection holding the stock row must block anyone else
     * trying to take it.
     */
    public function test_the_stock_row_is_genuinely_locked_while_a_reservation_is_in_flight(): void
    {
        $this->stockUp(10);

        $level = StockLevel::query()->where('sku', self::SKU)->firstOrFail();

        // A second, independent connection to the same database.
        config()->set('database.connections.rival', config('database.connections.pgsql'));
        $rival = DB::connection('rival');

        $rival->beginTransaction();
        $rival->table('stock_levels')->where('id', $level->id)->lockForUpdate()->first();

        try {
            // Fail fast instead of hanging the suite if the lock is held.
            DB::statement("SET LOCAL lock_timeout = '750ms'");

            $blocked = false;

            try {
                DB::transaction(function () use ($level) {
                    DB::statement("SET LOCAL lock_timeout = '750ms'");
                    DB::table('stock_levels')->where('id', $level->id)->lockForUpdate()->first();
                });
            } catch (QueryException $e) {
                $blocked = str_contains($e->getMessage(), 'lock timeout')
                    || str_contains($e->getMessage(), 'canceling statement');
            }

            $this->assertTrue(
                $blocked,
                'A second reservation must block on the stock row, not read past it.'
            );
        } finally {
            $rival->rollBack();
            $rival->disconnect();
        }
    }

    // --- real races ---------------------------------------------------------

    /**
     * Two staff confirm at the same instant, each wanting the only carton.
     *
     * Exactly one may win. Both winning is overselling — a customer is
     * promised stock that does not exist, and the shortfall is discovered in
     * the warehouse rather than on screen.
     */
    public function test_two_simultaneous_confirmations_cannot_both_take_the_last_carton(): void
    {
        $this->stockUp(12);

        $orders = [
            $this->submittedOrderFor(12, 1),
            $this->submittedOrderFor(12, 2),
        ];

        $outcomes = $this->raceConfirmations($orders);

        $this->assertSame(1, $outcomes['confirmed'], 'exactly one confirmation may win');
        $this->assertSame(1, $outcomes['out_of_stock'], 'the loser must be told the stock is gone');
        $this->assertSame(0, $outcomes['errored'], 'no unexpected failures');

        // 12 held once, not twice.
        $level = StockLevel::query()->where('sku', self::SKU)->firstOrFail();
        $this->assertSame(12, $level->qty_reserved);
        $this->assertSame(12, $level->qty_on_hand);
        $this->assertSame([], app(StockLedger::class)->reconcile());
    }

    /**
     * Four at once against three cartons' worth: three win, one is turned away.
     * A lock that only works for two racers is not a lock.
     */
    public function test_four_simultaneous_confirmations_are_capped_by_available_stock(): void
    {
        $this->stockUp(36);

        $orders = [];

        for ($n = 1; $n <= 4; $n++) {
            $orders[] = $this->submittedOrderFor(12, $n);
        }

        $outcomes = $this->raceConfirmations($orders);

        $this->assertSame(3, $outcomes['confirmed']);
        $this->assertSame(1, $outcomes['out_of_stock']);
        $this->assertSame(0, $outcomes['errored']);

        $level = StockLevel::query()->where('sku', self::SKU)->firstOrFail();
        $this->assertSame(36, $level->qty_reserved);
        $this->assertSame([], app(StockLedger::class)->reconcile());
    }

    /**
     * Fork one process per order and start them together.
     *
     * @param  list<Order>  $orders
     * @return array{confirmed: int, out_of_stock: int, errored: int}
     */
    private function raceConfirmations(array $orders): array
    {
        /*
         * A Postgres advisory lock as a starting gun.
         *
         * Aligning on wall-clock time alone was too loose: with only two
         * racers the processes often did not overlap, and the test passed even
         * with the row lock removed — a test that cannot fail is not a test.
         *
         * Instead the parent holds advisory lock 4242 exclusively before
         * forking. Each child asks for it in *shared* mode and blocks. When the
         * parent releases, every child is granted at once and they hit the
         * stock row together.
         */
        $gate = DB::connection();
        $gate->select('SELECT pg_advisory_lock(4242)');

        $pids = [];

        foreach ($orders as $order) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $gate->select('SELECT pg_advisory_unlock(4242)');
                $this->fail('Could not fork a process for the race.');
            }

            if ($pid === 0) {
                exit($this->childConfirm($order->id));
            }

            $pids[] = $pid;
        }

        // Give the children time to reach the gate and queue behind it.
        usleep(400_000);
        $gate->select('SELECT pg_advisory_unlock(4242)');

        $outcomes = ['confirmed' => 0, 'out_of_stock' => 0, 'errored' => 0];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;

            match ($code) {
                0 => $outcomes['confirmed']++,
                1 => $outcomes['out_of_stock']++,
                default => $outcomes['errored']++,
            };
        }

        return $outcomes;
    }

    /**
     * Runs inside a forked child. Returns the process exit code.
     *
     * 0 = confirmed, 1 = refused for stock, anything else = unexpected.
     */
    private function childConfirm(int $orderId): int
    {
        // The inherited connection belongs to the parent; sharing one socket
        // across processes corrupts both sides of the conversation.
        DB::purge();
        DB::reconnect();

        // Queue at the gate. Released the instant the parent lets go, so every
        // child starts within microseconds of the others.
        DB::select('SELECT pg_advisory_lock_shared(4242)');
        DB::select('SELECT pg_advisory_unlock_shared(4242)');

        try {
            $order = Order::findOrFail($orderId);
            app(OrderStateMachine::class)->confirm($order, User::findOrFail($order->created_by));

            return $order->refresh()->status === OrderStatus::Confirmed ? 0 : 3;
        } catch (InsufficientStockException) {
            return 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, "child failed: {$e->getMessage()}\n");

            return 2;
        }
    }
}
