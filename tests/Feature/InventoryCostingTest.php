<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\ProductCost;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory costing: moving average, and the COGS a shipment freezes.
 *
 * This is the sixth money-critical area. The five in CLAUDE.md are price
 * resolution, credit, reservation, settlement and tax; costing joins them for the
 * same reason they are on the list — a bug here is not a wrong pixel, it is a
 * wrong gross margin, and a wrong gross margin is a business decision made on a
 * number that was never true.
 *
 * The invariant everything else follows from:
 *
 *     total value received == total value issued, once the shelf is empty
 *
 * No rupiah may be created or destroyed by the arithmetic itself. Integer money
 * and repeated division make that easy to get wrong, which is why it is
 * asserted here on deliberately awkward numbers rather than on round ones.
 */
class InventoryCostingTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-COST-1';

    private Warehouse $warehouse;

    private User $finance;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->supplier = Supplier::factory()->create(['nama' => 'PT Pemasok Uji']);

        Product::factory()->create([
            'kode' => self::SKU, 'qty_per_ctn' => 12, 'satuan_dasar' => 'PCS',
        ]);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * Receive goods and post them, the way the panel does.
     *
     * @param  list<array{0: int, 1: int, 2?: string}>  $lines  [qty in pieces, cost each, sku?]
     */
    private function receive(array $lines): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);

        foreach ($lines as $i => $line) {
            [$qty, $unitCost] = $line;

            GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
                'goods_receipt_id' => $receipt->id,
                'sku' => $line[2] ?? self::SKU,
                'urutan' => $i + 1,
            ]);
        }

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    private function ship(int $qty, ?string $sku = null): StockMovement
    {
        return app(StockLedger::class)->record(
            sku: $sku ?? self::SKU,
            warehouseId: $this->warehouse->id,
            qtySigned: -$qty,
            reason: MovementReason::Pengiriman,
        );
    }

    private function cost(): ProductCost
    {
        return ProductCost::query()->where('sku', self::SKU)->firstOrFail();
    }

    // --- the moving average -------------------------------------------------

    public function test_a_receipt_sets_the_average_and_raises_stock(): void
    {
        $this->receive([[100, 10_000]]);

        $cost = $this->cost();

        $this->assertSame(100, $cost->qty_base);
        $this->assertSame(1_000_000, $cost->value_rupiah);
        $this->assertSame(10_000, $cost->unitCost());

        // And the goods are genuinely on the shelf.
        $this->assertSame(100, app(StockLedger::class)->available(self::SKU, $this->warehouse->id));
    }

    public function test_a_second_receipt_at_a_different_price_moves_the_average(): void
    {
        $this->receive([[100, 10_000]]);
        $this->receive([[100, 12_000]]);

        $cost = $this->cost();

        $this->assertSame(200, $cost->qty_base);
        $this->assertSame(2_200_000, $cost->value_rupiah);
        $this->assertSame(11_000, $cost->unitCost(), 'the average of 10.000 and 12.000 over equal quantities');
    }

    public function test_the_average_is_weighted_by_quantity_not_by_receipt(): void
    {
        // 900 cheap, 100 dear. A naive mean of the two prices would say 15.000.
        $this->receive([[900, 10_000]]);
        $this->receive([[100, 20_000]]);

        $this->assertSame(11_000, $this->cost()->unitCost());
    }

    /** The buyer's question the average cannot answer. */
    public function test_the_last_purchase_price_is_kept_alongside_the_average(): void
    {
        $this->receive([[100, 10_000]]);
        $this->receive([[100, 12_000]]);

        $this->assertSame(12_000, $this->cost()->last_cost_rupiah);
        $this->assertSame(11_000, $this->cost()->unitCost());
    }

    // --- COGS ---------------------------------------------------------------

    public function test_a_shipment_takes_value_out_at_the_average(): void
    {
        $this->receive([[100, 10_000]]);
        $this->receive([[100, 12_000]]);

        $movement = $this->ship(50);

        $this->assertSame(11_000, $movement->unit_cost_rupiah);
        $this->assertSame(-550_000, $movement->value_rupiah, 'COGS is signed like the quantity');

        $cost = $this->cost();
        $this->assertSame(150, $cost->qty_base);
        $this->assertSame(1_650_000, $cost->value_rupiah);
    }

    /**
     * The property that makes this an accounting record rather than a report.
     *
     * Buying stock today must not change what last month's sales cost. If COGS
     * were derived at read time from the current average, every purchase would
     * silently rewrite history — and the month you already reported to the
     * owner would quietly stop matching the report.
     */
    public function test_a_later_purchase_does_not_change_an_earlier_shipments_cogs(): void
    {
        $this->receive([[100, 10_000]]);

        $shipped = $this->ship(10);
        $this->assertSame(-100_000, $shipped->value_rupiah);

        // Prices double. The shipment above is untouched.
        $this->receive([[100, 20_000]]);

        $this->assertSame(-100_000, $shipped->refresh()->value_rupiah);
        $this->assertSame(10_000, $shipped->unit_cost_rupiah);
    }

    public function test_cost_of_goods_sold_sums_the_shipments(): void
    {
        $this->receive([[100, 10_000]]);
        $this->ship(10);
        $this->ship(5);

        $this->assertSame(150_000, app(InventoryValuation::class)->costOfGoodsSold());
    }

    /**
     * The number this whole change exists to make possible.
     *
     * Before cost was on the movement there was no way to compute it at all:
     * revenue was known, what the goods cost was not, so nobody could say
     * whether an order made money.
     */
    public function test_gross_margin_is_now_computable(): void
    {
        $this->receive([[100, 10_000]]);
        $this->ship(10);

        // Sold at 15.000 the piece.
        $revenue = 10 * 15_000;
        $cogs = app(InventoryValuation::class)->costOfGoodsSold();

        $this->assertSame(100_000, $cogs);
        $this->assertSame(50_000, $revenue - $cogs, 'gross profit');
        $this->assertSame(3333, intdiv(($revenue - $cogs) * 10_000, $revenue), 'margin in basis points');
    }

    // --- the arithmetic must not leak ---------------------------------------

    /**
     * Nothing may be created or destroyed by rounding.
     *
     * Deliberately awkward: quantities and prices chosen so that every average
     * is a recurring decimal. Whatever was paid in must come back out as COGS
     * by the time the shelf is empty, to the rupiah.
     */
    public function test_everything_paid_in_comes_back_out_as_cogs_to_the_rupiah(): void
    {
        $paid = 0;

        foreach ([[3, 10_001], [7, 13_337], [11, 999], [13, 70_007]] as [$qty, $unitCost]) {
            $this->receive([[$qty, $unitCost]]);
            $paid += $qty * $unitCost;
        }

        // Sell it down in equally awkward slices until the shelf is empty.
        $onHand = 3 + 7 + 11 + 13;

        foreach ([5, 9, 1, 17, 2] as $slice) {
            $take = min($slice, $onHand);

            if ($take <= 0) {
                break;
            }

            $this->ship($take);
            $onHand -= $take;
        }

        $this->assertSame(0, $onHand, 'the fixture must empty the shelf');

        $cost = $this->cost();
        $this->assertSame(0, $cost->qty_base);
        $this->assertSame(
            0,
            $cost->value_rupiah,
            'an empty shelf must be worth nothing — no stranded rupiah'
        );

        $this->assertSame(
            $paid,
            app(InventoryValuation::class)->costOfGoodsSold(),
            'total COGS must equal total paid'
        );
    }

    /**
     * Value must leave in proportion to quantity, not as the rounded unit cost
     * multiplied out.
     *
     * The difference is a rupiah or two per issue, which sounds ignorable and
     * is not: it accumulates in the stored balance, so inventory value drifts
     * away from what was actually paid and no report ties out.
     *
     * Asserted on the exact stored value rather than on the rounded average —
     * the average hides a one-rupiah drift, which is exactly how this bug
     * survives a test that looks like it covers it.
     */
    public function test_value_leaves_in_proportion_and_the_stored_balance_stays_exact(): void
    {
        // 83.351 over 7 pieces: an average that is not a whole rupiah.
        $this->receive([[3, 10_001]]);
        $this->receive([[4, 13_337]]);

        $this->assertSame(83_351, $this->cost()->value_rupiah);

        $movement = $this->ship(2);

        // 83.351 × 2 / 7 = 23.814,57 → 23.815, not 11.907 × 2 = 23.814.
        $this->assertSame(-23_815, $movement->value_rupiah);
        $this->assertSame(59_536, $this->cost()->value_rupiah);
        $this->assertSame(5, $this->cost()->qty_base);
    }

    /**
     * Issuing more than the valuation believes is on hand.
     *
     * Happens where the books and the shelf disagree — stock that arrived
     * before receipts existed, or a count that was never done. The movement
     * still has to happen, but it may only take out the value that was actually
     * there: anything else drives inventory value negative, which is not a
     * number that can appear on a balance sheet.
     */
    public function test_issuing_more_than_is_valued_never_drives_value_negative(): void
    {
        $valuation = app(InventoryValuation::class);

        $valuation->applyReceipt(self::SKU, 10, 10_000);

        $issued = $valuation->applyIssue(self::SKU, 15);

        $this->assertSame(10_000, $issued->value, 'only the value that was there');
        $this->assertSame(5, $issued->shortfall, 'and the gap is reported');

        $cost = $this->cost();
        $this->assertSame(0, $cost->qty_base);
        $this->assertSame(0, $cost->value_rupiah, 'never negative');
    }

    /** Cartons in, pieces out — the supplier invoices one, the ledger holds the other. */
    public function test_a_carton_priced_receipt_values_the_base_units(): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
        ]);

        // 10 cartons of 12, at 120.000 per carton = 10.000 per piece.
        GoodsReceiptLine::factory()->cartons(10, 12, 120_000)->create([
            'goods_receipt_id' => $receipt->id,
            'sku' => self::SKU,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        $cost = $this->cost();

        $this->assertSame(120, $cost->qty_base, 'the ledger is in base units');
        $this->assertSame(1_200_000, $cost->value_rupiah);
        $this->assertSame(10_000, $cost->unitCost());
    }

    // --- the cache is a cache -----------------------------------------------

    /**
     * Valuation must be reconstructible by replaying the ledger, exactly as
     * stock levels are. Because each movement carries the value that applied
     * when it happened, the replay is a plain sum and cannot disagree with the
     * movements it checks.
     */
    public function test_the_cached_valuation_always_matches_the_summed_ledger(): void
    {
        $this->receive([[100, 10_000]]);
        $this->ship(30);
        $this->receive([[50, 14_000]]);
        $this->ship(45);

        $this->assertSame([], app(InventoryValuation::class)->reconcile());
    }

    public function test_reconcile_reports_drift_when_the_cache_is_tampered_with(): void
    {
        $this->receive([[100, 10_000]]);

        ProductCost::query()->where('sku', self::SKU)->update(['value_rupiah' => 999]);

        $drift = app(InventoryValuation::class)->reconcile();

        $this->assertCount(1, $drift);
        $this->assertSame(self::SKU, $drift[0]['sku']);
        $this->assertSame(999, $drift[0]['cached_value']);
        $this->assertSame(1_000_000, $drift[0]['ledger_value']);
    }

    // --- honesty about what is not known ------------------------------------

    /**
     * Stock that arrived before receipts existed has no cost. Reporting it as
     * free would show as infinite margin, which nobody notices until it is in
     * front of the owner. It is left unvalued and counted instead.
     */
    public function test_stock_with_no_known_cost_ships_unvalued_rather_than_free(): void
    {
        // Straight into the ledger with no value, as a seeder or a legacy row.
        app(StockLedger::class)->record(
            sku: self::SKU,
            warehouseId: $this->warehouse->id,
            qtySigned: 20,
            reason: MovementReason::Penerimaan,
        );

        $movement = $this->ship(5);

        $this->assertNull($movement->value_rupiah, 'unknown is not zero');
        $this->assertNull($movement->unit_cost_rupiah);
        $this->assertSame(1, app(InventoryValuation::class)->unvaluedIssues());
        $this->assertSame(0, app(InventoryValuation::class)->costOfGoodsSold());
    }

    /**
     * Legacy stock is a work item, not a drift alarm.
     *
     * Stock that predates costing sits in the ledger with no value. Counting it
     * as reconciliation drift would report a permanent discrepancy that nobody
     * can clear, and a check that always cries wolf gets ignored — including on
     * the day it is right. It is reported as unvalued quantity instead, which
     * names the fix: count the shelf and enter an opening-balance receipt.
     */
    public function test_stock_from_before_costing_is_reported_as_unvalued_not_as_drift(): void
    {
        // 200 units in the ledger with no cost, as a pre-costing seeder left.
        app(StockLedger::class)->record(
            sku: self::SKU,
            warehouseId: $this->warehouse->id,
            qtySigned: 200,
            reason: MovementReason::Penerimaan,
        );
        $this->asIfWrittenBeforeTheColumnExisted(['stock_movements'], fn () => StockMovement::query()
            ->where('sku', self::SKU)
            ->update(['value_rupiah' => null]));
        ProductCost::query()->where('sku', self::SKU)->delete();

        // Then a proper receipt on top.
        $this->receive([[100, 10_000]]);

        $valuation = app(InventoryValuation::class);

        $this->assertSame([], $valuation->reconcile(), 'the valued half must still tie out');
        $this->assertSame([self::SKU => 200], $valuation->unvaluedQuantity());

        // And the average reflects only what is actually known to have cost.
        $this->assertSame(10_000, $this->cost()->unitCost());
    }

    public function test_an_outbound_movement_cannot_be_handed_a_cost_by_its_caller(): void
    {
        $this->receive([[100, 10_000]]);

        $this->expectExceptionMessage('valued by the running average');

        app(StockLedger::class)->record(
            sku: self::SKU,
            warehouseId: $this->warehouse->id,
            qtySigned: -5,
            reason: MovementReason::Pengiriman,
            valueRupiah: 1,
        );
    }

    /**
     * One average for the company means a transfer moves goods without moving
     * value. Nothing can be created by shuffling stock between buildings.
     */
    public function test_a_warehouse_transfer_is_value_neutral(): void
    {
        $this->receive([[100, 10_000]]);

        $other = Warehouse::factory()->create();
        $ledger = app(StockLedger::class);

        $ledger->record(self::SKU, $this->warehouse->id, -10, MovementReason::TransferKeluar);
        $ledger->record(self::SKU, $other->id, 10, MovementReason::TransferMasuk);

        $cost = $this->cost();

        $this->assertSame(100, $cost->qty_base, 'the company still holds 100');
        $this->assertSame(1_000_000, $cost->value_rupiah, 'and they are still worth what was paid');
        $this->assertSame([], app(InventoryValuation::class)->reconcile());
    }
}
