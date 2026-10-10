<?php

namespace Tests\Feature\Domain;

use App\Domain\Inventory\Costing\Recoster;
use App\Domain\Inventory\Costing\RecostJob;
use App\Domain\Inventory\Exceptions\NegativeStockException;
use App\Domain\Inventory\OpeningStockPoster;
use App\Domain\Inventory\OpnameApprover;
use App\Domain\Inventory\StockQuery;
use App\Domain\Inventory\TransferReceiver;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\AccountBalances;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\PeriodLock;
use App\Models\GeneralLedger\Account;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\ItemTransfer;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\StockOpnameOrder;
use App\Models\Inventory\StockOpnameResult;
use App\Models\Inventory\Unit;
use App\Models\Inventory\Warehouse;
use App\Models\Settings\AccessGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    private Item $item;

    private Warehouse $main;

    private Warehouse $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 09:00:00');
        CarbonImmutable::setTestNow('2026-11-15 09:00:00');
        $this->seed();
        $this->actingAsAdmin();
        $this->item = Item::query()->create(['number' => 'ITM-00001', 'name' => 'Widget', 'unit1_id' => Unit::query()->where('name', 'PCS')->value('id'), 'purchase_price' => 50_000]);
        $this->main = Warehouse::default();
        $this->branch = Warehouse::query()->create(['name' => 'Branch B']);
    }

    private function adjust(string $date, array $lines, ?string $number = null): InventoryAdjustment
    {
        return DB::transaction(function () use ($date, $lines, $number) {
            $adjustment = InventoryAdjustment::query()->create(['number' => $number ?? 'ADJ-'.uniqid(), 'trans_date' => $date, 'created_by' => auth()->id()]);
            foreach ($lines as $i => [$qty, $cost, $warehouse]) {
                $adjustment->lines()->create([
                    'sort' => $i, 'item_id' => $this->item->id, 'adjustment_type' => 'quantity',
                    'quantity' => $qty, 'unit_id' => $this->item->unit1_id, 'base_quantity' => $qty,
                    'unit_cost' => $cost, 'total_cost' => 0, 'warehouse_id' => $warehouse ?? $this->main->id,
                ]);
            }
            app(DocumentRepository::class)->created($adjustment);

            return $adjustment->fresh();
        });
    }

    private function cache(?Warehouse $warehouse = null): ItemCost
    {
        return ItemCost::query()->where('item_id', $this->item->id)->where('warehouse_id', ($warehouse ?? $this->main)->id)->firstOrFail();
    }

    public function test_the_moving_average_follows_receipts_and_issues_take_it(): void
    {
        $this->adjust('2026-11-01', [[10, 50_000, null]]);
        $this->assertSame('10.0000', $this->cache()->qty_on_hand);
        $this->assertSame('50000.0000', $this->cache()->avg_cost);

        $this->adjust('2026-11-02', [[10, 70_000, null]]);
        $this->assertSame('60000.0000', $this->cache()->avg_cost);
        $this->assertSame(1_200_000, $this->cache()->total_value);

        $issue = $this->adjust('2026-11-03', [[-5, 0, null]]);
        $movement = StockMovement::query()->active()->where('posting_id', $issue->posting->id)->firstOrFail();
        $this->assertSame('60000.0000', $movement->unit_cost);
        $this->assertSame(300_000, $movement->total_cost);
        $this->assertSame('15.0000', $this->cache()->qty_on_hand);
        $this->assertSame(900_000, $this->cache()->total_value);

        $inventory = Account::query()->where('no', '1300')->value('id');
        $this->assertSame(900_000, AccountBalances::asOf()[$inventory], 'the ledger and the journal agree');

        // Emptying the warehouse takes every remaining rupiah, never leaving a residue.
        $this->adjust('2026-11-04', [[-15, 0, null]]);
        $this->assertSame('0.0000', $this->cache()->qty_on_hand);
        $this->assertSame(0, $this->cache()->total_value);
        $this->assertSame(0, AccountBalances::asOf()[$inventory]);
    }

    public function test_a_back_dated_receipt_reposts_the_issues_after_it(): void
    {
        $this->adjust('2026-11-01', [[10, 50_000, null]]);
        $issue = $this->adjust('2026-11-10', [[-4, 0, null]]);
        $this->assertSame(200_000, StockMovement::query()->active()->where('posting_id', $issue->posting->id)->value('total_cost'));

        // A receipt dated before the issue, at a higher cost: the issue's cost must follow the new average.
        $this->adjust('2026-11-05', [[10, 90_000, null]]);

        $issue->refresh();
        $this->assertSame(2, $issue->posting->revision, 'the issue was re-posted');
        $this->assertSame(280_000, StockMovement::query()->active()->where('posting_id', $issue->posting->id)->value('total_cost'));
        $this->assertSame('16.0000', $this->cache()->qty_on_hand);
        $this->assertSame(1_400_000 - 280_000, $this->cache()->total_value);

        // Same-day: a receipt on the issue's day is costed before the issue.
        $this->adjust('2026-11-10', [[4, 110_000, null]]);
        $issue->refresh();
        $this->assertSame(3, $issue->posting->revision);
        $this->assertSame(306_667, StockMovement::query()->active()->where('posting_id', $issue->posting->id)->value('total_cost'), '24 units for 1.840.000, 4 taken at 76.666,67');
    }

    public function test_a_recost_job_reposts_in_one_pass_and_starts_no_other_job(): void
    {
        $this->adjust('2026-11-01', [[10, 50_000, null]]);
        $first = $this->adjust('2026-11-10', [[-2, 0, null]]);
        $second = $this->adjust('2026-11-11', [[-2, 0, null]]);
        $third = $this->adjust('2026-11-12', [[-2, 0, null]]);

        // More later documents than the request takes: a job, queued once the receipt is saved.
        Recoster::$syncLimit = 1;
        Queue::fake();
        try {
            $this->adjust('2026-11-05', [[10, 90_000, null]]);
            Queue::assertPushed(RecostJob::class, 1);
            $job = Queue::pushed(RecostJob::class)->first();
            $this->assertSame('recost', collect($job->middleware())->sole()->key, 'one batch at a time across the workers');

            // The job re-posts its list and everything those re-posts reach in one pass, queueing nothing more.
            $job->handle(app(Recoster::class));
            Queue::assertPushed(RecostJob::class, 1);
        } finally {
            Recoster::$syncLimit = 50;
        }
        foreach ([$first, $second, $third] as $issue) {
            $this->assertSame(140_000, StockMovement::query()->active()->where('posting_id', $issue->fresh()->posting->id)->value('total_cost'), 'at the new average of 70,000');
        }
    }

    public function test_deleting_a_receipt_recosts_later_issues_and_refuses_negative_stock(): void
    {
        $first = $this->adjust('2026-11-01', [[10, 50_000, null]]);
        $second = $this->adjust('2026-11-02', [[10, 70_000, null]]);
        $issue = $this->adjust('2026-11-03', [[-5, 0, null]]);
        $this->assertSame(300_000, StockMovement::query()->active()->where('posting_id', $issue->posting->id)->value('total_cost'));

        app(DocumentRepository::class)->delete($second);
        $issue->refresh();
        $this->assertSame(250_000, StockMovement::query()->active()->where('posting_id', $issue->posting->id)->value('total_cost'));
        $this->assertSame('5.0000', $this->cache()->qty_on_hand);

        try {
            app(DocumentRepository::class)->delete($first);
            $this->fail('deleting the only receipt would leave the issue without stock');
        } catch (NegativeStockException $e) {
            $this->assertStringContainsString('Widget', $e->getMessage());
        }
        $this->assertSame('5.0000', $this->cache()->qty_on_hand, 'nothing changed');
    }

    public function test_negative_stock_is_refused_unless_the_rule_allows_it(): void
    {
        $this->adjust('2026-11-01', [[3, 50_000, null]]);

        try {
            $this->adjust('2026-11-02', [[-5, 0, null]]);
            $this->fail('expected a negative stock refusal');
        } catch (NegativeStockException) {
        }
        $this->assertSame(1, InventoryAdjustment::query()->count(), 'the refused document was not kept');

        app(Preferensi::class)->set(PreferensiKey::AllowNegativeStock, true);
        $short = $this->adjust('2026-11-02', [[-5, 0, null]]);
        $this->assertSame('-2.0000', $this->cache()->qty_on_hand);
        $this->assertSame(250_000, StockMovement::query()->active()->where('posting_id', $short->posting->id)->value('total_cost'),
            'the three on hand at their value, the two beyond at the same average, not at nothing');

        $more = $this->adjust('2026-11-03', [[-1, 0, null]]);
        $this->assertSame(50_000, StockMovement::query()->active()->where('posting_id', $more->posting->id)->value('total_cost'), 'with nothing on hand, at the purchase price');
    }

    public function test_a_transfer_moves_goods_through_in_transit_at_their_cost(): void
    {
        $this->adjust('2026-11-01', [[10, 60_000, null]]);
        $send = ItemTransfer::query()->create(['number' => 'TRF-1', 'trans_date' => '2026-11-05', 'item_transfer_type' => 'send', 'warehouse_id' => $this->main->id, 'reference_warehouse_id' => $this->branch->id, 'created_by' => auth()->id()]);
        $send->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'quantity' => 6, 'unit_id' => $this->item->unit1_id, 'base_quantity' => 6]);
        app(DocumentRepository::class)->created($send);
        $send->refreshStatus();

        $transit = Warehouse::inTransit();
        $this->assertSame('4.0000', $this->cache()->qty_on_hand);
        $this->assertSame('6.0000', $this->cache($transit)->qty_on_hand);
        $this->assertSame('60000.0000', $this->cache($transit)->avg_cost);
        $this->assertSame('pending', $send->fresh()->status);

        $receive = app(TransferReceiver::class)->receive($send->fresh(), [$send->lines()->first()->id => 4], CarbonImmutable::parse('2026-11-07'));
        $this->assertSame('2.0000', $this->cache($transit)->qty_on_hand);
        $this->assertSame('4.0000', $this->cache($this->branch)->qty_on_hand);
        $this->assertSame(240_000, $this->cache($this->branch)->total_value);
        $this->assertSame('partial', $send->fresh()->status);
        $this->assertSame('TRF-PST-2611-0001', $receive->number);

        // A receipt deleted gives its quantity back: the goods are in transit again and can be received.
        $second = app(TransferReceiver::class)->receive($send->fresh(), [$send->lines()->first()->id => 2], CarbonImmutable::parse('2026-11-08'));
        $this->assertSame('processed', $send->fresh()->status);
        app(DocumentRepository::class)->delete($second->fresh());
        $this->assertSame('4.0000', $send->lines()->first()->fresh()->processed_quantity);
        $this->assertSame('partial', $send->fresh()->status);
        $this->assertSame('2.0000', $this->cache($transit)->qty_on_hand);

        app(TransferReceiver::class)->receive($send->fresh(), [$send->lines()->first()->id => 2], CarbonImmutable::parse('2026-11-08'));
        $this->assertSame('processed', $send->fresh()->status);
        $this->assertSame('0.0000', $this->cache($transit)->qty_on_hand);
        $this->assertSame('6.0000', StockQuery::onHand($this->item->id, $this->branch->id));
        $this->assertSame('10.0000', StockQuery::onHand($this->item->id), 'in transit is not counted as on hand');
    }

    public function test_opening_stock_from_the_item_posts_once_and_follows_edits(): void
    {
        $this->item->openingStocks()->create(['sort' => 0, 'trans_date' => '2026-01-01', 'quantity' => 20, 'unit_cost' => 45_000, 'warehouse_id' => $this->main->id]);
        app(OpeningStockPoster::class)->postFor($this->item);

        $this->assertSame('20.0000', $this->cache()->qty_on_hand);
        $this->assertSame(900_000, $this->cache()->total_value);
        $equity = Account::query()->where('no', '3300')->value('id');
        $this->assertSame(900_000, AccountBalances::asOf()[$equity]);

        $this->item->openingStocks()->update(['quantity' => 25]);
        app(OpeningStockPoster::class)->postFor($this->item->fresh());
        $this->assertSame('25.0000', $this->cache()->qty_on_hand);
        $this->assertSame(1, InventoryAdjustment::query()->where('is_opening', true)->count());

        $this->item->openingStocks()->delete();
        app(OpeningStockPoster::class)->postFor($this->item->fresh());
        $this->assertSame('0.0000', $this->cache()->qty_on_hand);
        $this->assertSame(0, InventoryAdjustment::query()->where('is_opening', true)->count());
    }

    public function test_a_stock_count_is_approved_by_someone_else_and_posts_the_variance(): void
    {
        $this->adjust('2026-11-01', [[10, 50_000, null]]);
        $counter = User::factory()->create();
        $approver = User::factory()->create();
        AccessGroup::query()->where('name', 'Purchasing')->firstOrFail()->users()->attach([$counter->id, $approver->id]); // Central's stock keepers (Purchasing) carry "approve transactions"

        $order = StockOpnameOrder::query()->create(['number' => 'SOO-1', 'trans_date' => '2026-11-09', 'start_date' => '2026-11-10', 'person_charged' => 'Alex Doe', 'warehouse_id' => $this->main->id, 'created_by' => $counter->id]);
        $result = StockOpnameResult::query()->create(['number' => 'SOR-1', 'trans_date' => '2026-11-10', 'stock_opname_order_id' => $order->id, 'created_by' => $counter->id]);
        $result->lines()->create(['sort' => 0, 'item_id' => $this->item->id, 'counted_qty' => 8, 'base_quantity' => 8]);
        app(OpnameApprover::class)->snapshotSystemQuantities($result->fresh()->load('order', 'lines'));
        $this->assertSame('10.0000', $result->lines()->first()->system_qty);

        try {
            app(OpnameApprover::class)->approve($result->fresh(), $counter);
            $this->fail('the counter must not approve their own count');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Segregation', $e->getMessage());
        }

        // November closed: the variance cannot post, so the approval is not kept either.
        $this->travelTo(Carbon::parse('2026-12-02 10:00:00'));
        app(PeriodLock::class)->close(2026, 11);
        $this->assertThrows(fn () => app(OpnameApprover::class)->approve($result->fresh(), $approver), \RuntimeException::class);
        $this->assertNotSame('approved', $result->fresh()->status);
        $this->assertTrue(app(OpnameApprover::class)->canApprove($result->fresh(), $approver), 'it can be approved once the month is open');
        app(PeriodLock::class)->reopen(2026, 11);

        $adjustment = app(OpnameApprover::class)->approve($result->fresh(), $approver);
        $this->assertNotNull($adjustment);
        $this->assertSame('-2.0000', $adjustment->lines()->first()->base_quantity);
        $this->assertSame('8.0000', $this->cache()->qty_on_hand);
        $this->assertSame('approved', $result->fresh()->status);
        $this->assertSame($approver->id, $result->fresh()->approved_by);
    }
}
