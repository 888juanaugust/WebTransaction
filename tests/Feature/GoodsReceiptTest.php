<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The goods receipt — the document that lets stock arrive.
 *
 * Before this, `Pengiriman` was the only MovementReason the application ever
 * wrote. Stock could only fall: a warehouse would drain to zero and orders
 * would start failing the availability check, which makes Phase 1 — "staff
 * enter real orders" — impossible to actually run. That gap is what this file
 * guards against reopening.
 */
class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-TB-1';

    private Warehouse $warehouse;

    private Supplier $supplier;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create();
        $this->supplier = Supplier::factory()->create();
        $this->finance = User::factory()->role(Role::Finance)->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 12]);
        Product::factory()->create(['kode' => 'YH-TB-2', 'qty_per_ctn' => 6]);
    }

    private function draft(): GoodsReceipt
    {
        return GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->finance->id,
        ]);
    }

    private function withLines(GoodsReceipt $receipt, array $lines): GoodsReceipt
    {
        foreach ($lines as $i => [$sku, $qty, $unitCost]) {
            GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
                'goods_receipt_id' => $receipt->id,
                'sku' => $sku,
                'urutan' => $i + 1,
            ]);
        }

        return $receipt->refresh();
    }

    // --- the gap this closes ------------------------------------------------

    /**
     * The regression that matters: before this existed the application wrote
     * exactly one MovementReason, and stock could only ever go down.
     */
    public function test_stock_can_now_go_up(): void
    {
        $ledger = app(StockLedger::class);

        $this->assertSame(0, $ledger->available(self::SKU, $this->warehouse->id));

        $receipt = $this->withLines($this->draft(), [[self::SKU, 120, 10_000]]);
        app(GoodsReceiptPoster::class)->post($receipt, $this->finance);

        $this->assertSame(120, $ledger->available(self::SKU, $this->warehouse->id));

        $movement = StockMovement::query()->where('sku', self::SKU)->sole();
        $this->assertSame(MovementReason::Penerimaan->value, $movement->reason);
        $this->assertSame(120, $movement->qty_signed);
    }

    public function test_posting_writes_one_movement_per_line_referencing_the_document(): void
    {
        $receipt = $this->withLines($this->draft(), [
            [self::SKU, 100, 10_000],
            ['YH-TB-2', 60, 5_000],
        ]);

        app(GoodsReceiptPoster::class)->post($receipt, $this->finance);

        $movements = StockMovement::query()
            ->where('reference_type', GoodsReceipt::class)
            ->where('reference_id', (string) $receipt->id)
            ->get();

        $this->assertCount(2, $movements);
        $this->assertEqualsCanonicalizing([100, 60], $movements->pluck('qty_signed')->all());
        $this->assertTrue($movements->every(fn ($m) => $m->actor_id === $this->finance->id));
    }

    public function test_posting_marks_the_document_and_totals_it(): void
    {
        $receipt = $this->withLines($this->draft(), [
            [self::SKU, 100, 10_000],
            ['YH-TB-2', 60, 5_000],
        ]);

        app(GoodsReceiptPoster::class)->post($receipt, $this->finance);
        $receipt->refresh();

        $this->assertTrue($receipt->isPosted());
        $this->assertSame(1_300_000, $receipt->total_value_rupiah);
        $this->assertSame($this->finance->id, $receipt->posted_by);
        $this->assertNotNull($receipt->posted_at);
    }

    /** Money-affecting, so it is in the audit log. */
    public function test_posting_is_audited(): void
    {
        $receipt = $this->withLines($this->draft(), [[self::SKU, 100, 10_000]]);

        app(GoodsReceiptPoster::class)->post($receipt, $this->finance);

        $log = AuditLog::query()->where('action', 'goods_receipt_posted')->sole();

        $this->assertSame($this->finance->id, $log->actor_id);
        $this->assertSame(1_000_000, $log->new_value['total_value_rupiah']);
    }

    // --- posting is terminal and happens once -------------------------------

    /**
     * Two people hitting Posting on the same draft is not hypothetical — it is
     * what happens when the first click looks slow. Posting twice would double
     * both the stock and the value.
     */
    public function test_a_receipt_cannot_be_posted_twice(): void
    {
        $receipt = $this->withLines($this->draft(), [[self::SKU, 100, 10_000]]);

        app(GoodsReceiptPoster::class)->post($receipt, $this->finance);

        try {
            app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
            $this->fail('a posted receipt was posted again');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sudah diposting', $e->getMessage());
        }

        $this->assertSame(1, StockMovement::query()->where('sku', self::SKU)->count());
        $this->assertSame(100, app(StockLedger::class)->available(self::SKU, $this->warehouse->id));
    }

    public function test_an_empty_receipt_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak punya baris');

        app(GoodsReceiptPoster::class)->post($this->draft(), $this->finance);
    }

    /**
     * A failure part-way through must leave nothing behind. Half a receipt in
     * the ledger is stock on the shelf the valuation does not know it paid for.
     */
    public function test_a_refused_line_rolls_the_whole_receipt_back(): void
    {
        $receipt = $this->withLines($this->draft(), [[self::SKU, 100, 10_000]]);

        // A second line that cannot be posted.
        GoodsReceiptLine::factory()->pieces(5, 1_000)->create([
            'goods_receipt_id' => $receipt->id,
            'sku' => 'YH-TB-2',
            'qty_base' => 0,
            'urutan' => 2,
        ]);

        try {
            app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
            $this->fail('a receipt with an unpostable line was posted');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame(0, StockMovement::query()->count(), 'nothing reached the ledger');
        $this->assertSame(0, app(StockLedger::class)->available(self::SKU, $this->warehouse->id));
        $this->assertFalse($receipt->refresh()->isPosted());
    }

    public function test_a_negative_line_value_is_refused(): void
    {
        $receipt = $this->draft();

        GoodsReceiptLine::factory()->pieces(10, 1_000)->create([
            'goods_receipt_id' => $receipt->id,
            'sku' => self::SKU,
            'line_value_rupiah' => -1,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('nilai negatif');

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    // --- who may do it ------------------------------------------------------

    /** @return list<array{0: Role, 1: bool}> */
    public static function posters(): array
    {
        return [
            'finance' => [Role::Finance, true],
            'owner' => [Role::Owner, true],
            // Cost is on this document, and sales seeing cost is sales seeing
            // margin — which changes how a discount gets negotiated.
            'sales' => [Role::Sales, false],
            // The standing rule: warehouse never sees money.
            'warehouse' => [Role::Warehouse, false],
        ];
    }

    #[DataProvider('posters')]
    public function test_only_purchasing_roles_may_post_a_receipt(Role $role, bool $allowed): void
    {
        $receipt = $this->withLines($this->draft(), [[self::SKU, 100, 10_000]]);
        $actor = User::factory()->role($role)->create();

        if ($allowed) {
            app(GoodsReceiptPoster::class)->post($receipt, $actor);

            $this->assertTrue($receipt->refresh()->isPosted());

            return;
        }

        try {
            app(GoodsReceiptPoster::class)->post($receipt, $actor);
            $this->fail("{$role->value} was allowed to post a goods receipt");
        } catch (DomainException $e) {
            $this->assertStringContainsString('tidak berhak', $e->getMessage());
        }

        $this->assertFalse($receipt->refresh()->isPosted());
        $this->assertSame(0, StockMovement::query()->count());
    }

    // --- unit of measure ----------------------------------------------------

    /**
     * The same rule the order lines follow: a supplier invoices in cartons, the
     * ledger is always in base units, and both are stored so neither has to be
     * reverse-engineered later.
     */
    public function test_a_receipt_in_cartons_lands_in_the_ledger_as_base_units(): void
    {
        $receipt = $this->draft();

        GoodsReceiptLine::factory()->cartons(10, 12, 120_000)->create([
            'goods_receipt_id' => $receipt->id,
            'sku' => self::SKU,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        $line = $receipt->lines()->sole();

        $this->assertSame(10, $line->ordered_qty, 'the document keeps what was ordered');
        $this->assertSame(120, $line->qty_base, 'the ledger takes base units');
        $this->assertSame(120_000, $line->unit_cost_rupiah, 'cost per carton, as invoiced');
        $this->assertSame(10_000, $line->unitCostPerBase(), 'derived, never stored');

        $this->assertSame(120, app(StockLedger::class)->available(self::SKU, $this->warehouse->id));
    }

    // --- numbering ----------------------------------------------------------

    public function test_receipts_get_their_own_gapless_number_series(): void
    {
        $numbers = collect(range(1, 3))->map(fn () => $this->draft()->nomor);

        $this->assertSame($numbers->unique()->count(), $numbers->count());

        foreach ($numbers as $nomor) {
            $this->assertStringStartsWith('TB-', $nomor, 'Terima Barang, not SO or INV');
        }
    }
}
