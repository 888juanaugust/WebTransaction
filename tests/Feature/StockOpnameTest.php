<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Accounting\ProfitAndLoss;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Stock\StockOpnamePoster;
use App\Domain\Stock\StockOpnameSheet;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Counting the shelf, and what happens when it disagrees with the record.
 *
 * Two controls carry this document, and neither is about arithmetic:
 *
 *   - **The counter cannot approve their own count.** This is the one document
 *     whose purpose is to make missing goods disappear from the record.
 *   - **A stale count is refused, not applied.** If stock moved between
 *     drawing the sheet and approving it, adjusting to the counted figure
 *     would set the shelf to a number that was true an hour ago and swallow
 *     whatever moved since.
 */
class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-OP-1';

    private const SKU_2 = 'YH-OP-2';

    private Warehouse $gudang;

    private User $counter;

    private User $finance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->counter = User::factory()->role(Role::Warehouse)->create();
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        Product::factory()->create(['kode' => self::SKU_2, 'qty_per_ctn' => 6, 'satuan_dasar' => 'PCS']);
    }

    // ------------------------------------------------------------- the sheet

    public function test_the_sheet_lists_what_the_system_believes_is_on_each_shelf(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $this->stockUp(self::SKU_2, 40, 25_000);

        $opname = app(StockOpnameSheet::class)->draw($this->gudang, $this->counter);

        $this->assertCount(2, $opname->lines);
        $this->assertSame(100, $opname->lines[0]->qty_system);
        $this->assertSame(40, $opname->lines[1]->qty_system);
    }

    public function test_an_uncounted_line_is_blank_rather_than_zero(): void
    {
        // Zero would be indistinguishable from "counted, and the shelf was
        // empty" — which is the one finding a count exists to make.
        $this->stockUp(self::SKU, 100, 60_000);

        $opname = app(StockOpnameSheet::class)->draw($this->gudang, $this->counter);

        $this->assertNull($opname->lines[0]->qty_counted);
        $this->assertFalse($opname->lines[0]->isCounted());
        $this->assertNull($opname->lines[0]->variance());
    }

    public function test_a_shelf_the_system_thinks_is_empty_still_appears(): void
    {
        /*
         * That is exactly where unrecorded stock accumulates, and a sheet that
         * omits it can never find any.
         */
        $this->stockUp(self::SKU, 100, 60_000);
        app(StockLedger::class)->record(self::SKU_2, $this->gudang->id, 5, MovementReason::Koreksi);
        app(StockLedger::class)->record(self::SKU_2, $this->gudang->id, -5, MovementReason::Koreksi);

        $opname = app(StockOpnameSheet::class)->draw($this->gudang, $this->counter);

        $this->assertSame([self::SKU, self::SKU_2], $opname->lines->pluck('sku')->all());
        $this->assertSame(0, $opname->lines[1]->qty_system);
    }

    public function test_a_sheet_can_be_drawn_for_a_few_skus_only(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $this->stockUp(self::SKU_2, 40, 25_000);

        $opname = app(StockOpnameSheet::class)->draw($this->gudang, $this->counter, [self::SKU_2]);

        $this->assertSame([self::SKU_2], $opname->lines->pluck('sku')->all());
    }

    // ------------------------------------------------------------ the count

    public function test_a_count_that_agrees_moves_nothing_and_posts_nothing(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 100]);

        $posted = app(StockOpnamePoster::class)->post($opname, $this->finance);

        $this->assertSame(0, $posted->selisih_qty);
        $this->assertSame(0, $posted->selisih_rupiah);
        $this->assertSame(100, $this->onHand(self::SKU));
        $this->assertSame(0, JournalEntry::query()->where('jenis', JournalEntry::JENIS_SELISIH_OPNAME)->count());
    }

    public function test_a_shortfall_takes_stock_off_the_shelf(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94]);

        $posted = app(StockOpnamePoster::class)->post($opname, $this->finance);

        $this->assertSame(94, $this->onHand(self::SKU));
        $this->assertSame(-6, $posted->selisih_qty);
        $this->assertSame(-360_000, $posted->selisih_rupiah);

        $movement = StockMovement::query()->where('reference_type', StockOpname::class)->sole();
        $this->assertSame(-6, $movement->qty_signed);
        $this->assertSame('opname', $movement->reason);
    }

    public function test_a_surplus_puts_stock_back_on_at_the_running_average(): void
    {
        // No invoice says what a surplus cost — it is stock we apparently
        // already owned and had not recorded, so it enters at the average.
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 103]);

        $posted = app(StockOpnamePoster::class)->post($opname, $this->finance);

        $this->assertSame(103, $this->onHand(self::SKU));
        $this->assertSame(3, $posted->selisih_qty);
        $this->assertSame(180_000, $posted->selisih_rupiah);
    }

    public function test_lines_nobody_counted_are_left_alone(): void
    {
        // A partial count is normal — one aisle at a time. The SKUs nobody
        // reached must not be adjusted to zero.
        $this->stockUp(self::SKU, 100, 60_000);
        $this->stockUp(self::SKU_2, 40, 25_000);

        $opname = $this->counted([self::SKU => 94]);
        app(StockOpnamePoster::class)->post($opname, $this->finance);

        $this->assertSame(94, $this->onHand(self::SKU));
        $this->assertSame(40, $this->onHand(self::SKU_2));
    }

    public function test_a_count_of_nothing_is_refused(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = app(StockOpnameSheet::class)->draw($this->gudang, $this->counter);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('belum ada yang dihitung');

        app(StockOpnamePoster::class)->post($opname, $this->finance);
    }

    public function test_a_negative_count_is_refused(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = app(StockOpnameSheet::class)->draw($this->gudang, $this->counter);

        $this->expectException(DomainException::class);

        app(StockOpnameSheet::class)->record($opname, [self::SKU => -1], $this->counter);
    }

    // ------------------------------------------------------------ the books

    public function test_a_shortfall_lands_in_the_variance_account(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $ledger = app(Ledger::class);

        $before = $ledger->balanceOf(AccountCode::PERSEDIAAN);

        app(StockOpnamePoster::class)->post($this->counted([self::SKU => 94]), $this->finance);

        $this->assertSame($before - 360_000, $ledger->balanceOf(AccountCode::PERSEDIAAN));
        $this->assertSame(360_000, $ledger->balanceOf(AccountCode::SELISIH_PERSEDIAAN));
        $this->assertTrue($ledger->isBalanced());
    }

    public function test_a_surplus_credits_the_variance_account(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $ledger = app(Ledger::class);

        app(StockOpnamePoster::class)->post($this->counted([self::SKU => 103]), $this->finance);

        $this->assertSame(-180_000, $ledger->balanceOf(AccountCode::SELISIH_PERSEDIAAN));
        $this->assertTrue($ledger->isBalanced());
    }

    public function test_the_variance_is_a_cost_of_trading_not_an_overhead(): void
    {
        // It hangs under the HPP header, so it lands above gross profit —
        // shrinkage moves with how much stock is handled.
        $this->stockUp(self::SKU, 100, 60_000);

        app(StockOpnamePoster::class)->post($this->counted([self::SKU => 94]), $this->finance);

        $pl = ProfitAndLoss::forPeriod(
            now()->startOfYear(), now()->endOfYear()
        );

        $this->assertSame(360_000, $pl->totalHargaPokok());
        $this->assertSame(0, $pl->totalBeban());
    }

    public function test_inventory_still_ties_to_the_valuation_afterwards(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);

        app(StockOpnamePoster::class)->post($this->counted([self::SKU => 94]), $this->finance);

        $this->assertSame(
            app(InventoryValuation::class)->totalValue(),
            app(Ledger::class)->balanceOf(AccountCode::PERSEDIAAN),
        );
        $this->assertTrue(app(LedgerReconciliation::class)->isClean());
    }

    public function test_the_books_use_the_value_the_movement_carried(): void
    {
        /*
         * Not quantity times the average recomputed afterwards. On an average
         * that does not divide evenly those two differ by a rupiah, and
         * Persediaan is a control account — a rupiah apart is a reconciliation
         * that fails every day until somebody chases it.
         */
        $this->stockUp(self::SKU, 100, 60_000);
        $this->stockUp(self::SKU, 50, 71_111);

        $posted = app(StockOpnamePoster::class)->post($this->counted([self::SKU => 143]), $this->finance);

        $movement = StockMovement::query()->where('reference_type', StockOpname::class)->sole();

        $this->assertSame((int) $movement->value_rupiah, $posted->selisih_rupiah);
        $this->assertSame(
            app(InventoryValuation::class)->totalValue(),
            app(Ledger::class)->balanceOf(AccountCode::PERSEDIAAN),
        );
    }

    // ---------------------------------------------------------- the controls

    public function test_the_counter_cannot_approve_their_own_count(): void
    {
        /*
         * The control the document exists for. Somebody who can both count a
         * shelf and sign off what they found can walk out with stock and file
         * the paperwork themselves.
         */
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94], $this->owner);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak boleh menyetujui hitungannya sendiri');

        app(StockOpnamePoster::class)->post($opname, $this->owner);
    }

    #[DataProvider('countingRoles')]
    public function test_who_may_draw_and_fill_a_sheet(Role $role, bool $allowed): void
    {
        $this->stockUp(self::SKU, 100, 60_000);

        if (! $allowed) {
            $this->expectException(DomainException::class);
        }

        $opname = app(StockOpnameSheet::class)->draw($this->gudang, User::factory()->role($role)->create());

        $this->assertSame($this->gudang->id, $opname->warehouse_id);
    }

    public static function countingRoles(): array
    {
        return [
            'gudang' => [Role::Warehouse, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'keuangan' => [Role::Finance, false],
        ];
    }

    #[DataProvider('approvingRoles')]
    public function test_who_may_approve_a_count(Role $role, bool $allowed): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94]);

        if (! $allowed) {
            $this->expectException(DomainException::class);
        }

        $posted = app(StockOpnamePoster::class)->post($opname, User::factory()->role($role)->create());

        $this->assertTrue($posted->isPosted());
    }

    public static function approvingRoles(): array
    {
        return [
            // Warehouse counts; approving what they found is somebody else's.
            'gudang' => [Role::Warehouse, false],
            'sales' => [Role::Sales, false],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
        ];
    }

    public function test_counting_and_approving_are_disjoint_for_warehouse(): void
    {
        $warehouse = $this->counter->role();

        $this->assertTrue($warehouse->canCountStock());
        $this->assertFalse($warehouse->canApproveStockCount());

        $finance = $this->finance->role();

        $this->assertFalse($finance->canCountStock());
        $this->assertTrue($finance->canApproveStockCount());
    }

    // ------------------------------------------------------------- staleness

    public function test_a_count_is_refused_if_stock_moved_since_the_sheet_was_drawn(): void
    {
        /*
         * Adjusting to a stale count would set the shelf to a number that was
         * true an hour ago, silently swallowing whatever moved in between.
         */
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94]);

        // A delivery lands while the count is waiting for approval.
        $this->stockUp(self::SKU, 20, 60_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stok berubah sejak lembar opname dibuat');

        app(StockOpnamePoster::class)->post($opname->fresh(), $this->finance);
    }

    public function test_the_refusal_names_the_sku_and_both_numbers(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94]);
        $this->stockUp(self::SKU, 20, 60_000);

        try {
            app(StockOpnamePoster::class)->post($opname->fresh(), $this->finance);
            $this->fail('A stale count was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString(self::SKU, $e->getMessage());
            $this->assertStringContainsString('lembar 100', $e->getMessage());
            $this->assertStringContainsString('sekarang 120', $e->getMessage());
        }

        $this->assertSame(120, $this->onHand(self::SKU));
        $this->assertTrue($opname->fresh()->isDraft());
    }

    public function test_a_movement_on_an_uncounted_line_does_not_block_the_count(): void
    {
        // Only the lines being adjusted have to be stable. A delivery of a
        // different SKU is nobody's business here.
        $this->stockUp(self::SKU, 100, 60_000);
        $this->stockUp(self::SKU_2, 40, 25_000);

        $opname = $this->counted([self::SKU => 94]);
        $this->stockUp(self::SKU_2, 10, 25_000);

        $posted = app(StockOpnamePoster::class)->post($opname->fresh(), $this->finance);

        $this->assertSame(-6, $posted->selisih_qty);
    }

    // ------------------------------------------------------------ terminality

    public function test_an_opname_cannot_be_posted_twice(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94]);
        $poster = app(StockOpnamePoster::class);

        $poster->post($opname, $this->finance);

        try {
            $poster->post($opname->fresh(), $this->finance);
            $this->fail('An opname was posted twice.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sudah diposting', $e->getMessage());
        }

        $this->assertSame(94, $this->onHand(self::SKU));
    }

    public function test_a_posted_opname_cannot_be_recounted(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94]);
        app(StockOpnamePoster::class)->post($opname, $this->finance);

        $this->expectException(DomainException::class);

        app(StockOpnameSheet::class)->record($opname->fresh(), [self::SKU => 80], $this->counter);
    }

    public function test_posting_records_who_counted_and_who_approved(): void
    {
        $this->stockUp(self::SKU, 100, 60_000);
        $opname = $this->counted([self::SKU => 94]);

        $posted = app(StockOpnamePoster::class)->post($opname, $this->finance);

        $this->assertSame($this->counter->id, $posted->counted_by);
        $this->assertSame($this->finance->id, $posted->posted_by);

        $log = AuditLog::query()->where('action', 'stock_opname_posted')->sole();
        $this->assertSame($this->counter->id, $log->new_value['counted_by']);
        $this->assertSame($this->finance->id, $log->actor_id);
    }

    // --- helpers ------------------------------------------------------------

    private function onHand(string $sku): int
    {
        return (int) StockLevel::query()
            ->where('sku', $sku)
            ->where('warehouse_id', $this->gudang->id)
            ->value('qty_on_hand');
    }

    private function stockUp(string $sku, int $qty, int $unitCost): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => $sku, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    /** @param  array<string, int>  $counts */
    private function counted(array $counts, ?User $by = null): StockOpname
    {
        $by ??= $this->counter;
        $sheet = app(StockOpnameSheet::class);

        $opname = $sheet->draw($this->gudang, $by);

        return $sheet->record($opname, $counts, $by);
    }
}
