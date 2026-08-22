<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\ReorderAdvisor;
use App\Domain\Stock\ReorderSuggestion;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What is running out, and how much to order.
 *
 * The arithmetic is simple; what these tests hold down is the three ways it
 * goes expensively wrong. Ignoring stock already on order doubles the
 * warehouse. Counting reserved stock as cover means a part is "in stock" until
 * the morning somebody goes to pick it. Suggesting a quantity that is not a
 * whole carton means whoever places the order rounds it themselves, in
 * whichever direction they feel like.
 */
class ReorderAdvisorTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $gudang;

    private Supplier $pemasok;

    private ReorderAdvisor $advisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-15 09:00:00');

        $this->gudang = Warehouse::query()->firstOrCreate(
            ['kode' => 'GD-PUSAT'],
            ['nama' => 'Gudang Pusat', 'aktif' => true],
        );
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Anugerah Sparepart']);

        $this->advisor = app(ReorderAdvisor::class);
    }

    // --- the line itself ----------------------------------------------------

    public function test_a_part_selling_steadily_with_plenty_on_the_shelf_is_not_listed(): void
    {
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);          // one a day
        $this->onHand('YH-1001', 500);

        $this->assertSame([], $this->skus());
    }

    public function test_a_part_below_its_point_is_listed(): void
    {
        /*
         * One a day, three weeks assumed lead time plus two weeks safety, so
         * the point is 35. Thirty on the shelf is under it.
         */
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $row = $this->only();

        $this->assertSame('YH-1001', $row->sku);
        $this->assertSame(35, $row->titikPesanUlang);
        $this->assertSame(30, $row->posisi);
    }

    public function test_exactly_at_the_point_is_already_late(): void
    {
        // At the point, the shelf runs out exactly as the replacement lands.
        // Waiting for it to go under means ordering a day late every time.
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 35);

        $this->assertSame(['YH-1001'], $this->skus());
    }

    public function test_a_part_that_has_never_sold_gets_no_point_at_all(): void
    {
        /*
         * Not a point of zero. There is no rate to derive one from, and "never
         * sold" is a buying question rather than a restocking one — it belongs
         * on the ageing report, where it already is. A zero point would put
         * every dead SKU on this list at "0 of 0" and bury the real rows.
         */
        $this->product('YH-9999');
        $this->onHand('YH-9999', 0);

        $this->assertSame([], $this->skus());
    }

    public function test_an_inactive_product_is_never_suggested(): void
    {
        $this->product('YH-1001', ['aktif' => false]);
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 0);

        $this->assertSame([], $this->skus());
    }

    // --- the three expensive mistakes ---------------------------------------

    public function test_stock_already_on_order_stops_it_being_ordered_twice(): void
    {
        /*
         * The failure this prevents: below the line on Monday, somebody orders,
         * still below the line on Tuesday because nothing has arrived, somebody
         * orders again. Twice the warehouse and twice the money.
         */
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $this->onPurchaseOrder('YH-1001', 200, PurchaseOrderStatus::Dikirim);

        $this->assertSame([], $this->skus());
    }

    public function test_a_draft_purchase_order_counts_as_on_order_too(): void
    {
        // A draft raised from this very screen is a decision already made.
        // Ignoring it means the button produces a second order each press.
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $this->onPurchaseOrder('YH-1001', 200, PurchaseOrderStatus::Draft);

        $this->assertSame([], $this->skus());
    }

    public function test_a_cancelled_order_covers_nothing(): void
    {
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $this->onPurchaseOrder('YH-1001', 200, PurchaseOrderStatus::Dibatalkan);

        $this->assertSame(['YH-1001'], $this->skus());
    }

    public function test_the_part_of_an_order_already_received_no_longer_covers_anything(): void
    {
        // Otherwise a part-delivered order goes on covering the whole quantity
        // forever and the balance is never chased.
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $this->onPurchaseOrder('YH-1001', 200, PurchaseOrderStatus::Dikirim, received: 195);

        $row = $this->only();

        $this->assertSame(5, $row->onOrder);
        $this->assertSame(35, $row->posisi);
    }

    public function test_stock_reserved_for_a_confirmed_order_is_not_cover(): void
    {
        /*
         * Those goods are going to leave. Counting them is how a part shows as
         * in stock right up to the morning the warehouse goes to pick it.
         */
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 100, reserved: 80);

        $row = $this->only();

        $this->assertSame(20, $row->posisi);
        $this->assertSame(100, $row->onHand);
        $this->assertSame(80, $row->reserved);
    }

    public function test_the_suggestion_is_always_whole_cartons(): void
    {
        /*
         * Suppliers sell by the dus. A suggestion of 37 pieces of a part that
         * comes 12 to a carton is a suggestion nobody can place, and whoever
         * places it rounds in whichever direction they feel like.
         */
        $this->product('YH-1001', ['qty_per_ctn' => 12]);
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $row = $this->only();

        $this->assertSame(0, $row->saranQtyBase % 12);
        $this->assertSame($row->saranQtyCtn * 12, $row->saranQtyBase);

        // And rounded up: never short of what the target asked for.
        $this->assertGreaterThanOrEqual(77 - 30, $row->saranQtyBase);
    }

    public function test_a_part_sold_loose_is_not_a_special_case(): void
    {
        $this->product('YH-1001', ['qty_per_ctn' => 1]);
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $row = $this->only();

        $this->assertSame(1, $row->qtyPerCtn);
        $this->assertSame($row->saranQtyCtn, $row->saranQtyBase);
    }

    // --- how much ------------------------------------------------------------

    public function test_it_orders_up_to_a_target_rather_than_up_to_the_line(): void
    {
        /*
         * Filling exactly to the reorder point puts the part straight back on
         * this list the following week. The target is lead time plus safety
         * plus six weeks of cover.
         */
        $this->product('YH-1001', ['qty_per_ctn' => 1]);
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        // 1/day × (21 + 14 + 42) = 77, less the 30 on hand.
        $this->assertSame(47, $this->only()->saranQtyBase);
    }

    public function test_what_is_already_on_order_comes_off_the_suggestion(): void
    {
        $this->product('YH-1001', ['qty_per_ctn' => 1, 'titik_pesan_ulang_manual' => 100]);
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);
        $this->onPurchaseOrder('YH-1001', 20, PurchaseOrderStatus::Dikirim);

        // Target 77, position 50 — the 20 coming counts towards it.
        $this->assertSame(27, $this->only()->saranQtyBase);
    }

    // --- lead time ------------------------------------------------------------

    public function test_lead_time_is_measured_from_real_deliveries(): void
    {
        $this->product('YH-1001', ['qty_per_ctn' => 1]);
        $this->sold('YH-1001', 365);

        /*
         * Twenty on the shelf. Under the measured point of 21 and comfortably
         * over the assumed one of 35 — so the row appearing at all is the
         * assertion: had the lead time not been measured, thirty-five would
         * have been the line and this part would have looked fine.
         */
        $this->onHand('YH-1001', 20);

        // Sent on the 1st, arrived on the 8th: seven days, not the assumed 21.
        $this->receivedAfter('YH-1001', sentDaysAgo: 40, arrivedDaysAgo: 33);

        $row = $this->only();

        $this->assertSame(7, $row->leadTimeHari);
        $this->assertTrue($row->leadTimeTerukur);
        $this->assertSame(21, $row->titikPesanUlang);   // 1/day × (7 + 14)

        // And the order is sized on the measured figure too: 7 + 14 + 42 = 63.
        $this->assertSame(43, $row->saranQtyBase);
    }

    public function test_an_assumed_lead_time_says_that_it_is_assumed(): void
    {
        // An assumption presented as a measurement is worse than no figure.
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $row = $this->only();

        $this->assertSame(ReorderAdvisor::DEFAULT_LEAD_TIME_DAYS, $row->leadTimeHari);
        $this->assertFalse($row->leadTimeTerukur);
    }

    public function test_a_receipt_with_no_purchase_order_behind_it_measures_nothing(): void
    {
        // Stock that simply turned up says nothing about how long the supplier
        // takes, because nobody asked them for it.
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);
        $this->receiptWithoutPurchaseOrder('YH-1001');

        $this->assertFalse($this->only()->leadTimeTerukur);
    }

    // --- the human override ---------------------------------------------------

    public function test_a_manual_point_wins_outright(): void
    {
        /*
         * Not blended with the derived one. "The higher of the two" would be a
         * number nobody typed and nobody measured.
         */
        $this->product('YH-1001', ['titik_pesan_ulang_manual' => 500]);
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 400);

        $row = $this->only();

        $this->assertSame(500, $row->titikPesanUlang);
        $this->assertTrue($row->titikManual);
    }

    public function test_a_manual_point_can_put_a_never_sold_part_on_the_list(): void
    {
        // A part stocked for a contract that has not started. History says
        // nothing; the person who typed the figure knows something history
        // cannot.
        $this->product('YH-9999', ['titik_pesan_ulang_manual' => 40, 'qty_per_ctn' => 1]);
        $this->onHand('YH-9999', 10);

        $row = $this->only();

        $this->assertSame(40, $row->titikPesanUlang);
        $this->assertSame(30, $row->saranQtyBase);
    }

    public function test_a_discontinued_line_is_kept_off_the_list_entirely(): void
    {
        /*
         * Different from `aktif = false`: it still sells down its remaining
         * stock and still appears in the catalogue. It must simply never be
         * bought again, and the arithmetic would keep asking.
         */
        $this->product('YH-1001', ['jangan_pesan_ulang' => true]);
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 0);

        $this->assertSame([], $this->skus());
    }

    // --- ordering and grouping -------------------------------------------------

    public function test_what_is_out_of_stock_comes_first(): void
    {
        $this->product('SLOW-01', ['qty_per_ctn' => 1]);
        $this->sold('SLOW-01', 365);
        $this->onHand('SLOW-01', 30);

        $this->product('HABIS-01', ['qty_per_ctn' => 1]);
        $this->sold('HABIS-01', 365);
        $this->onHand('HABIS-01', 0);

        $this->assertSame(['HABIS-01', 'SLOW-01'], $this->skus());
    }

    public function test_something_nothing_is_covering_beats_something_on_order(): void
    {
        // A part under its point with a purchase order against it is being
        // handled. Putting it in front of somebody again produces a second one.
        $this->product('COVERED', ['qty_per_ctn' => 1]);
        $this->sold('COVERED', 365);
        $this->onHand('COVERED', 5);
        $this->onPurchaseOrder('COVERED', 10, PurchaseOrderStatus::Dikirim);

        $this->product('NAKED', ['qty_per_ctn' => 1]);
        $this->sold('NAKED', 365);
        $this->onHand('NAKED', 20);

        $this->assertSame(['NAKED', 'COVERED'], $this->skus());
    }

    public function test_shortfall_is_ranked_proportionally_not_absolutely(): void
    {
        /*
         * Forty under a point of fifty is a crisis; forty under a point of
         * four thousand is a rounding error. Sorting on the raw gap puts the
         * fast movers on top every time regardless of how covered they are.
         */
        $this->product('FAST-01', ['qty_per_ctn' => 1]);
        $this->sold('FAST-01', 36_500);            // 100/day → point 3500
        $this->onHand('FAST-01', 3_460);

        $this->product('SMALL-01', ['qty_per_ctn' => 1]);
        $this->sold('SMALL-01', 365);              // 1/day → point 35
        $this->onHand('SMALL-01', 5);

        $this->assertSame(['SMALL-01', 'FAST-01'], $this->skus());
    }

    public function test_it_names_the_supplier_we_last_bought_from(): void
    {
        $lain = Supplier::factory()->create(['nama' => 'PT Sumber Lain']);

        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $this->receivedFrom('YH-1001', $lain, daysAgo: 90);
        $this->receivedFrom('YH-1001', $this->pemasok, daysAgo: 20);

        $row = $this->only();

        $this->assertSame($this->pemasok->id, $row->supplierId);
        $this->assertSame('PT Anugerah Sparepart', $row->supplierNama);
    }

    public function test_a_part_never_bought_from_anybody_still_gets_listed(): void
    {
        // Opening stock counted in at go-live has no supplier behind it. It
        // still runs out, and somebody still has to decide who to ring.
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30);

        $row = $this->only();

        $this->assertNull($row->supplierId);
        $this->assertNull($row->supplierNama);
    }

    public function test_for_supplier_returns_only_that_suppliers_parts(): void
    {
        $lain = Supplier::factory()->create(['nama' => 'PT Sumber Lain']);

        foreach ([['A-01', $this->pemasok], ['B-01', $lain]] as [$sku, $supplier]) {
            $this->product($sku);
            $this->sold($sku, 365);
            $this->onHand($sku, 10);
            $this->receivedFrom($sku, $supplier, daysAgo: 30);
        }

        $rows = $this->advisor->forSupplier($this->pemasok->id);

        $this->assertCount(1, $rows);
        $this->assertSame('A-01', $rows[0]->sku);
    }

    // --- what a row says about itself ------------------------------------------

    public function test_days_of_cover_is_null_when_nothing_sells(): void
    {
        // Infinity is not something anybody can sort by, and "never sells" is
        // a different problem from "sells slowly".
        $this->product('YH-9999', ['titik_pesan_ulang_manual' => 40]);
        $this->onHand('YH-9999', 10);

        $this->assertNull($this->only()->sisaHari());
    }

    public function test_days_of_cover_counts_only_unreserved_stock(): void
    {
        $this->product('YH-1001');
        $this->sold('YH-1001', 365);
        $this->onHand('YH-1001', 30, reserved: 20);

        // Ten left at one a day.
        $this->assertSame(10.0, $this->only()->sisaHari());
    }

    // --- helpers ----------------------------------------------------------------

    /** @return list<string> */
    private function skus(): array
    {
        return array_map(fn (ReorderSuggestion $s) => $s->sku, $this->advisor->suggestions());
    }

    private function only(): ReorderSuggestion
    {
        $rows = $this->advisor->suggestions();

        $this->assertCount(1, $rows, 'Expected exactly one suggestion.');

        return $rows[0];
    }

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'kode' => $sku,
            'description' => "Barang {$sku}",
            'qty_per_ctn' => 10,
        ], $attributes));
    }

    /** Shipments spread evenly over the measurement window. */
    private function sold(string $sku, int $qty): void
    {
        DB::table('stock_movements')->insert([
            'sku' => $sku,
            'warehouse_id' => $this->gudang->id,
            'qty_signed' => -$qty,
            'reason' => MovementReason::Pengiriman->value,
            'reference_type' => 'order',
            'reference_id' => 1,
            'created_at' => Carbon::now()->subDays(180),
        ]);
    }

    private function onHand(string $sku, int $qty, int $reserved = 0): void
    {
        DB::table('stock_levels')->updateOrInsert(
            ['sku' => $sku, 'warehouse_id' => $this->gudang->id],
            ['qty_on_hand' => $qty, 'qty_reserved' => $reserved],
        );
    }

    private function onPurchaseOrder(
        string $sku,
        int $qty,
        PurchaseOrderStatus $status,
        int $received = 0,
    ): void {
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
        ]);
        $po->forceFill(['status' => $status, 'sent_at' => Carbon::now()->subDays(5)])->save();

        PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $po->id,
            'sku' => $sku,
            'urutan' => 1,
            'qty_base' => $qty,
            'qty_base_received' => $received,
        ]);
    }

    /** A delivery that arrived against a purchase order, so lead time is real. */
    private function receivedAfter(string $sku, int $sentDaysAgo, int $arrivedDaysAgo): void
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
        ]);
        $po->forceFill([
            'status' => PurchaseOrderStatus::Selesai,
            'sent_at' => Carbon::now()->subDays($sentDaysAgo),
        ])->save();

        $this->receipt($sku, $this->pemasok, $arrivedDaysAgo, $po->id);
    }

    private function receiptWithoutPurchaseOrder(string $sku): void
    {
        $this->receipt($sku, $this->pemasok, 30, null);
    }

    private function receivedFrom(string $sku, Supplier $supplier, int $daysAgo): void
    {
        $this->receipt($sku, $supplier, $daysAgo, null);
    }

    private function receipt(string $sku, Supplier $supplier, int $daysAgo, ?int $poId): void
    {
        $at = Carbon::now()->subDays($daysAgo);

        $id = DB::table('goods_receipts')->insertGetId([
            'nomor' => 'TB-'.fake()->unique()->numerify('######'),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->gudang->id,
            'purchase_order_id' => $poId,
            'status' => 'posted',
            'tanggal_terima' => $at->toDateString(),
            'total_value_rupiah' => 0,
            'created_by' => User::factory()->create()->id,
            'posted_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('goods_receipt_lines')->insert([
            'goods_receipt_id' => $id,
            'sku' => $sku,
            'urutan' => 1,
            'ordered_unit' => 'PCS',
            'ordered_qty' => 1,
            'qty_per_ctn_snapshot' => 10,
            'satuan_dasar_snapshot' => 'PCS',
            'qty_base' => 1,
            'unit_cost_rupiah' => 1000,
            'line_value_rupiah' => 1000,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
