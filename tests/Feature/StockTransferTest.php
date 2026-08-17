<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Stock\InsufficientStockException;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Stock\StockTransferPoster;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Moving stock between our own warehouses.
 *
 * One property carries this whole feature: a transfer is **value-neutral**.
 * `product_costs` is keyed by SKU rather than by warehouse, so walking a
 * carton across the yard changes nothing about what the inventory is worth —
 * and the ledger has to reflect that exactly, not approximately. Most of these
 * tests are about the arithmetic that makes "exactly" true.
 */
class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-TF-1';

    private Warehouse $pusat;

    private Warehouse $cabang;

    private User $gudang;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pusat = Warehouse::factory()->create(['kode' => 'GD-PUSAT', 'nama' => 'Gudang Pusat']);
        $this->cabang = Warehouse::factory()->create(['kode' => 'GD-CAB', 'nama' => 'Gudang Cabang']);
        $this->gudang = User::factory()->role(Role::Warehouse)->create();
        $this->finance = User::factory()->role(Role::Finance)->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
    }

    // ---------------------------------------------------------- the movement

    public function test_stock_leaves_one_warehouse_and_arrives_at_the_other(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);

        app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);

        $this->assertSame(70, $this->onHand($this->pusat));
        $this->assertSame(30, $this->onHand($this->cabang));
    }

    public function test_both_legs_are_written_as_movements(): void
    {
        // Without the pair, the two halves are separate adjustments and
        // nothing says they are the same cartons.
        $this->stockUp($this->pusat, 100, 60_000);
        $transfer = app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);

        $movements = StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', (string) $transfer->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame(-30, $movements[0]->qty_signed);
        $this->assertSame('transfer_keluar', $movements[0]->reason);
        $this->assertSame(30, $movements[1]->qty_signed);
        $this->assertSame('transfer_masuk', $movements[1]->reason);
    }

    public function test_a_transfer_cannot_take_more_than_the_shelf_has(): void
    {
        $this->stockUp($this->pusat, 10, 60_000);

        $this->expectException(InsufficientStockException::class);

        app(StockTransferPoster::class)->post($this->transferOf(11), $this->gudang);
    }

    public function test_reserved_stock_is_not_available_to_transfer(): void
    {
        // Stock fenced off for a confirmed order is promised to a customer.
        // Moving it to another warehouse would break that promise silently.
        $this->stockUp($this->pusat, 100, 60_000);
        StockLevel::query()->where('warehouse_id', $this->pusat->id)
            ->update(['qty_reserved' => 80]);

        $this->expectException(InsufficientStockException::class);

        app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);
    }

    public function test_a_warehouse_cannot_transfer_to_itself(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);

        $this->expectException(LogicException::class);

        app(StockLedger::class)->transfer(self::SKU, $this->pusat->id, $this->pusat->id, 5);
    }

    // ----------------------------------------------------------- the value

    public function test_a_transfer_changes_nothing_about_what_the_stock_is_worth(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);

        $before = app(InventoryValuation::class)->totalValue();

        app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);

        $this->assertSame($before, app(InventoryValuation::class)->totalValue());
    }

    public function test_value_neutrality_holds_on_an_average_that_does_not_divide(): void
    {
        /*
         * The case the whole design exists for. Two receipts at different
         * prices leave an average with no exact per-unit value. Calling
         * record() twice would take value out at that average and put it back
         * at the average *recomputed after* the removal — the two differ by a
         * rupiah, and a rupiah destroyed by walking a carton across the yard
         * is a rupiah nobody can ever explain.
         */
        $this->stockUp($this->pusat, 100, 60_000);
        $this->stockUp($this->pusat, 50, 71_111);

        $before = app(InventoryValuation::class)->totalValue();
        $this->assertNotSame(0, $before % 150, 'The fixture needs an average that does not divide evenly.');

        app(StockTransferPoster::class)->post($this->transferOf(37), $this->gudang);

        $this->assertSame($before, app(InventoryValuation::class)->totalValue());
    }

    public function test_the_value_that_leaves_is_the_value_that_arrives(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);
        $this->stockUp($this->pusat, 50, 71_111);

        $transfer = app(StockTransferPoster::class)->post($this->transferOf(37), $this->gudang);

        $movements = StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', (string) $transfer->id)
            ->orderBy('id')
            ->get();

        $this->assertSame(-$movements[0]->value_rupiah, $movements[1]->value_rupiah);
        $this->assertSame((int) $movements[1]->value_rupiah, (int) $transfer->total_value_rupiah);
    }

    public function test_the_ledger_replay_still_agrees_after_a_transfer(): void
    {
        // reconcile() must change nothing. A transfer that netted to anything
        // other than zero would show up here.
        $this->stockUp($this->pusat, 100, 60_000);
        $this->stockUp($this->pusat, 50, 71_111);

        app(StockTransferPoster::class)->post($this->transferOf(37), $this->gudang);

        $this->assertSame([], app(InventoryValuation::class)->reconcile());
    }

    public function test_transferring_stock_that_was_never_costed_leaves_it_uncosted(): void
    {
        /*
         * Opening balances entered before costing existed carry no value. If
         * the inbound leg wrote a valued zero, unvaluedQuantity() would report
         * a permanent shortfall on a shelf that balances — the out is unvalued
         * and the in would not be, so they would never net off.
         */
        app(StockLedger::class)->record(
            self::SKU, $this->pusat->id, 100, MovementReason::Koreksi
        );
        StockMovement::query()->update(['value_rupiah' => null, 'unit_cost_rupiah' => null]);

        $before = app(InventoryValuation::class)->unvaluedQuantity();

        app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);

        $this->assertSame($before, app(InventoryValuation::class)->unvaluedQuantity());
    }

    // ------------------------------------------------------------ the books

    public function test_a_transfer_posts_no_journal_entry_at_all(): void
    {
        /*
         * Not an omission. The total value of the inventory is identical
         * before and after, so there is nothing for double entry to say. An
         * entry moving Persediaan to Persediaan would be a row that means
         * nothing.
         */
        $this->stockUp($this->pusat, 100, 60_000);
        $ledger = app(Ledger::class);

        $before = $ledger->balanceOf(AccountCode::PERSEDIAAN);
        $entries = JournalEntry::query()->count();

        app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);

        $this->assertSame($before, $ledger->balanceOf(AccountCode::PERSEDIAAN));
        $this->assertSame($entries, JournalEntry::query()->count());
    }

    public function test_the_control_account_still_ties_after_a_transfer(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);

        app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);

        $this->assertTrue(app(LedgerReconciliation::class)->isClean());
    }

    // ------------------------------------------------------------ the paperwork

    #[DataProvider('roles')]
    public function test_who_may_post_a_transfer(Role $role, bool $allowed): void
    {
        $this->stockUp($this->pusat, 100, 60_000);
        $transfer = $this->transferOf(30);

        if (! $allowed) {
            $this->expectException(DomainException::class);
        }

        $posted = app(StockTransferPoster::class)->post($transfer, User::factory()->role($role)->create());

        $this->assertTrue($posted->isPosted());
    }

    public static function roles(): array
    {
        return [
            'gudang' => [Role::Warehouse, true],
            'pemilik' => [Role::Owner, true],
            // Nobody who cannot move the goods should be recording that they moved.
            'sales' => [Role::Sales, false],
            'keuangan' => [Role::Finance, false],
        ];
    }

    public function test_a_transfer_cannot_be_posted_twice(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);
        $transfer = $this->transferOf(30);
        $poster = app(StockTransferPoster::class);

        $poster->post($transfer, $this->gudang);

        try {
            $poster->post($transfer->fresh(), $this->gudang);
            $this->fail('A transfer was posted twice.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sudah diposting', $e->getMessage());
        }

        $this->assertSame(70, $this->onHand($this->pusat));
        $this->assertSame(30, $this->onHand($this->cabang));
    }

    public function test_a_refused_posting_moves_nothing_at_all(): void
    {
        // Two lines: the first fits, the second does not. The first must not
        // survive the second's refusal.
        $this->stockUp($this->pusat, 100, 60_000);

        $transfer = $this->transferOf(30);
        StockTransferLine::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'sku' => self::SKU,
            'urutan' => 2,
            'qty_base' => 999,
        ]);

        try {
            app(StockTransferPoster::class)->post($transfer->fresh(), $this->gudang);
        } catch (InsufficientStockException) {
            // expected
        }

        $this->assertSame(100, $this->onHand($this->pusat));
        $this->assertSame(0, $this->onHand($this->cabang));
        $this->assertSame(0, StockMovement::query()->where('reference_type', StockTransfer::class)->count());
        $this->assertTrue($transfer->fresh()->isDraft());
    }

    public function test_posting_is_audited(): void
    {
        $this->stockUp($this->pusat, 100, 60_000);

        app(StockTransferPoster::class)->post($this->transferOf(30), $this->gudang);

        $log = AuditLog::query()->where('action', 'stock_transfer_posted')->sole();

        $this->assertSame(30, $log->new_value['qty_base']);
        $this->assertSame($this->gudang->id, $log->actor_id);
    }

    // --- helpers ------------------------------------------------------------

    private function onHand(Warehouse $warehouse): int
    {
        return (int) StockLevel::query()
            ->where('sku', self::SKU)
            ->where('warehouse_id', $warehouse->id)
            ->value('qty_on_hand');
    }

    private function stockUp(Warehouse $warehouse, int $qty, int $unitCost): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    private function transferOf(int $qty): StockTransfer
    {
        $transfer = StockTransfer::create([
            'nomor' => app(DocumentNumberGenerator::class)->nextStockTransferNumber(),
            'from_warehouse_id' => $this->pusat->id,
            'to_warehouse_id' => $this->cabang->id,
            'tanggal' => now()->toDateString(),
            'created_by' => $this->gudang->id,
        ]);

        StockTransferLine::factory()->create([
            'stock_transfer_id' => $transfer->id,
            'sku' => self::SKU,
            'urutan' => 1,
            'qty_base' => $qty,
        ]);

        return $transfer->refresh();
    }
}
