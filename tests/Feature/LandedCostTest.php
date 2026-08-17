<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Purchasing\AllocationBasis;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\LandedCostAllocator;
use App\Domain\Purchasing\LandedCostPoster;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\LandedCost;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Freight and duty finding their way into the cost of the goods.
 *
 * A carton does not cost what the supplier invoiced. It costs that plus
 * everything paid to get it onto the shelf, and leaving those out makes every
 * margin figure in the system optimistic by exactly the amount forgotten.
 *
 * The hard part is timing, and most of these tests are about it. The
 * forwarder's invoice arrives weeks after the container, by which point some
 * of the shipment has been sold. Cost is frozen at the movement — that is a
 * CLAUDE.md invariant — so those shipments cannot be restated. The charge has
 * to split: what is still on the shelf gets dearer, and what has gone is a
 * cost of the period we found out.
 */
class LandedCostTest extends TestCase
{
    use RefreshDatabase;

    private const SKU_A = 'YH-LC-1';

    private const SKU_B = 'OS-LC-2';

    private Warehouse $gudang;

    private User $finance;

    private User $owner;

    private Supplier $pemasok;

    private Supplier $forwarder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);
        $this->forwarder = Supplier::factory()->create(['nama' => 'PT Angkutan Laut']);

        Product::factory()->create(['kode' => self::SKU_A, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        Product::factory()->create(['kode' => self::SKU_B, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
    }

    // ------------------------------------------------- the basic arithmetic

    public function test_a_charge_raises_the_cost_of_goods_that_are_all_still_here(): void
    {
        /*
         * The simple case, and the one that has to be exactly right before any
         * of the harder ones matter. 100 pieces at 60,000 is 6,000,000; a
         * 600,000 freight charge is a tenth of that, so the average goes from
         * 60,000 to 66,000 and inventory is worth 6,600,000.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);

        $valuation = app(InventoryValuation::class);
        $this->assertSame(60_000, $valuation->unitCost(self::SKU_A));

        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(600_000, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(0, (int) $landedCost->ke_hpp_rupiah);

        $this->assertSame(66_000, $valuation->unitCost(self::SKU_A));
        $this->assertSame(6_600_000, $valuation->totalValue());
    }

    public function test_the_share_belonging_to_goods_already_sold_goes_to_cost_of_sales(): void
    {
        /*
         * The whole point of the feature. 100 arrived, 40 have been sold, and
         * the freight invoice turns up now. Four tenths of it belongs to goods
         * that are gone — restating those shipments is refused by the frozen
         * COGS rule, so that share is a cost of this period instead.
         *
         * The remaining 60 absorb 360,000, which is 6,000 each on top of
         * 60,000. Note what does *not* happen: the 60 do not absorb the whole
         * 600,000, which would make them cost 70,000 each and show a loss on
         * every subsequent sale.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->sell(self::SKU_A, 40);

        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(360_000, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(240_000, (int) $landedCost->ke_hpp_rupiah);

        $valuation = app(InventoryValuation::class);

        // 60 × 60,000 = 3,600,000, plus 360,000 of freight = 3,960,000.
        $this->assertSame(3_960_000, $valuation->totalValue());
        $this->assertSame(66_000, $valuation->unitCost(self::SKU_A));
    }

    public function test_a_charge_on_a_shipment_that_has_entirely_gone_is_all_cost_of_sales(): void
    {
        // Nothing left to make dearer. The alternative — refusing to post —
        // would strand the charge in the clearing account forever.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->sell(self::SKU_A, 100);

        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(0, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(600_000, (int) $landedCost->ke_hpp_rupiah);

        // No uplift movement was written, because there was nothing to uplift.
        $this->assertSame(0, StockMovement::query()
            ->where('reason', MovementReason::BiayaPerolehan->value)->count());
    }

    public function test_stock_from_another_shipment_does_not_absorb_this_charge(): void
    {
        /*
         * 100 arrived on the shipment being costed and 40 have gone, but
         * another 500 of the same SKU are on the shelf from a delivery this
         * freight bill had nothing to do with.
         *
         * This is why the measure is what has gone out since the shipment
         * landed rather than what is on hand now. Reading the balance would
         * see 560 against 100 received, decide everything is still here, and
         * put the whole charge onto stock — quietly moving cost onto goods
         * somebody else shipped. Capping the balance at 100 would not help:
         * 100 of 100 is still all of it.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->sell(self::SKU_A, 40);
        $this->postedReceipt([[self::SKU_A, 500, 60_000]]);

        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(360_000, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(240_000, (int) $landedCost->ke_hpp_rupiah);
    }

    public function test_moving_stock_between_our_own_warehouses_does_not_count_as_sold(): void
    {
        /*
         * A transfer writes an outbound movement, but the goods have not gone
         * anywhere as far as the company is concerned — the inbound leg puts
         * them back. Counting the outbound leg would charge the shipment for
         * its own internal paperwork: walk 40 cartons across the yard and 40%
         * of the freight would be written off as if they had been sold.
         */
        $cabang = Warehouse::factory()->create(['nama' => 'Gudang Cabang']);
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        app(StockLedger::class)->transfer(
            sku: self::SKU_A,
            fromWarehouseId: $this->gudang->id,
            toWarehouseId: $cabang->id,
            qtyBase: 40,
            actor: $this->finance,
        );

        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(600_000, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(0, (int) $landedCost->ke_hpp_rupiah);
    }

    public function test_a_shortfall_found_by_a_stock_count_does_count_as_gone(): void
    {
        // Unlike a transfer. Those cartons are not on any shelf of ours, so
        // the freight paid to bring them in is not recoverable through margin.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        app(StockLedger::class)->record(
            sku: self::SKU_A,
            warehouseId: $this->gudang->id,
            qtySigned: -40,
            reason: MovementReason::Opname,
            actor: $this->finance,
        );

        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(360_000, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(240_000, (int) $landedCost->ke_hpp_rupiah);
    }

    // ------------------------------------------------------------- the basis

    public function test_by_value_the_dearer_goods_absorb_more(): void
    {
        // 6,000,000 of A and 2,000,000 of B: three quarters and one quarter.
        $receipt = $this->postedReceipt([
            [self::SKU_A, 100, 60_000],
            [self::SKU_B, 100, 20_000],
        ]);

        $charge = $this->postedCharge(800_000);
        $landedCost = $this->allocate($charge, [$receipt], AllocationBasis::Nilai);

        $shares = $landedCost->lines()->pluck('amount_rupiah', 'sku');

        $this->assertSame(600_000, (int) $shares[self::SKU_A]);
        $this->assertSame(200_000, (int) $shares[self::SKU_B]);
    }

    public function test_by_quantity_the_same_shipment_splits_evenly(): void
    {
        // Same two lines, same charge, and a completely different answer —
        // which is exactly why the basis is a choice on the document.
        $receipt = $this->postedReceipt([
            [self::SKU_A, 100, 60_000],
            [self::SKU_B, 100, 20_000],
        ]);

        $charge = $this->postedCharge(800_000);
        $landedCost = $this->allocate($charge, [$receipt], AllocationBasis::Kuantitas);

        $shares = $landedCost->lines()->pluck('amount_rupiah', 'sku');

        $this->assertSame(400_000, (int) $shares[self::SKU_A]);
        $this->assertSame(400_000, (int) $shares[self::SKU_B]);
    }

    public function test_the_shares_add_up_to_the_charge_on_awkward_numbers(): void
    {
        /*
         * Three lines and a prime charge, so no split is clean. mulDiv per
         * line would lose a rupiah here and the clearing account would carry
         * it forever.
         */
        $receipt = $this->postedReceipt([
            [self::SKU_A, 7, 13_331],
            [self::SKU_B, 11, 7_777],
        ]);

        $charge = $this->postedCharge(999_997);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(999_997, (int) $landedCost->lines()->sum('amount_rupiah'));
        $this->assertSame(
            999_997,
            (int) $landedCost->ke_persediaan_rupiah + (int) $landedCost->ke_hpp_rupiah,
        );

        // And what the lines say adds up to what the document says. These are
        // the figures on the screen somebody signs off, so a rupiah of drift
        // between the rows and the total is an argument waiting to happen.
        $this->assertSame(
            (int) $landedCost->ke_persediaan_rupiah,
            (int) $landedCost->lines()->sum('ke_persediaan_rupiah'),
        );
        $this->assertSame(
            (int) $landedCost->ke_hpp_rupiah,
            (int) $landedCost->lines()->sum('ke_hpp_rupiah'),
        );
    }

    public function test_the_line_shares_add_up_when_one_sku_arrives_on_several_lines(): void
    {
        /*
         * Three batches of the same part, partly sold, and a charge that
         * divides into thirds badly. The uplift for the SKU is decided once —
         * 151 rupiah — and then has to be shown against three lines that each
         * carry 101, 101 and 100 of the charge.
         *
         * Rounding each of those independently gives 51 + 51 + 50 = 152, which
         * is a rupiah more than the movement actually carried. Nothing would
         * break loudly: the journal and the valuation would still agree with
         * each other, and only the rows on this one screen would fail to sum
         * to their own total — which is exactly the sort of discrepancy that
         * makes somebody stop trusting the document.
         */
        $receipt = $this->postedReceipt([
            [self::SKU_A, 10, 1_000],
            [self::SKU_A, 10, 1_000],
            [self::SKU_A, 10, 1_000],
        ]);
        $this->sell(self::SKU_A, 15);

        $charge = $this->postedCharge(302);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->assertSame(151, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(
            151,
            (int) $landedCost->lines()->sum('ke_persediaan_rupiah'),
        );
        $this->assertSame(
            302,
            (int) $landedCost->lines()->sum('ke_persediaan_rupiah')
            + (int) $landedCost->lines()->sum('ke_hpp_rupiah'),
        );
    }

    public function test_the_line_shares_still_add_up_when_one_sku_arrives_in_two_warehouses(): void
    {
        /*
         * The uplift is written per warehouse, so the split happens twice: the
         * SKU's share across its warehouses, then each warehouse's share
         * across its lines. Rounding at either step has to land exactly, or
         * the rows on the document stop summing to its total.
         */
        $cabang = Warehouse::factory()->create(['nama' => 'Gudang Cabang']);

        $pusat = $this->postedReceipt([[self::SKU_A, 7, 13_331]]);
        $lain = $this->postedReceipt([[self::SKU_A, 11, 7_777]], $cabang);

        $charge = $this->postedCharge(999_997);
        $landedCost = $this->allocate($charge, [$pusat, $lain]);

        $this->assertSame(
            (int) $landedCost->ke_persediaan_rupiah,
            (int) $landedCost->lines()->sum('ke_persediaan_rupiah'),
        );
        $this->assertSame(999_997, (int) $landedCost->lines()->sum('amount_rupiah'));

        // Two warehouses, so two uplift movements, and together they carry
        // exactly what the journal is about to debit to Persediaan.
        $this->assertSame(2, StockMovement::query()
            ->where('reason', MovementReason::BiayaPerolehan->value)->count());
        $this->assertSame(
            (int) $landedCost->ke_persediaan_rupiah,
            (int) StockMovement::query()
                ->where('reason', MovementReason::BiayaPerolehan->value)
                ->sum('value_rupiah'),
        );
    }

    public function test_a_charge_spread_over_two_receipts_covers_both(): void
    {
        $first = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $second = $this->postedReceipt([[self::SKU_B, 100, 20_000]]);

        $charge = $this->postedCharge(800_000);
        $landedCost = $this->allocate($charge, [$first, $second]);

        $this->assertSame(2, $landedCost->lines()->count());
        $this->assertSame(800_000, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame([$first->nomor, $second->nomor], $landedCost->receiptNumbers());
    }

    public function test_one_sku_across_two_receipts_is_counted_once(): void
    {
        /*
         * The same SKU on two deliveries in one allocation. Asking "how much
         * is on hand" per line would look at the same shelf twice: 60 on hand
         * against 100 received on each line, so each would think it was 60%
         * still here — and the split would be 60% when the truth is 30%.
         */
        $first = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $second = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->sell(self::SKU_A, 140);

        $charge = $this->postedCharge(1_000_000);
        $landedCost = $this->allocate($charge, [$first, $second]);

        // 60 on hand of 200 received: 30% to stock, 70% to cost of sales.
        $this->assertSame(300_000, (int) $landedCost->ke_persediaan_rupiah);
        $this->assertSame(700_000, (int) $landedCost->ke_hpp_rupiah);
    }

    // ------------------------------------------------------- the stock ledger

    public function test_the_uplift_is_written_into_the_ledger_and_not_applied_behind_it(): void
    {
        /*
         * It would be less code to move the value straight onto product_costs.
         * reconcile() proves the cached pair by summing the movements, so
         * value applied behind its back would report a drift it cannot
         * explain — and a person asking why this SKU's average jumped would
         * find nothing in its history saying so.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $movement = StockMovement::query()
            ->where('reason', MovementReason::BiayaPerolehan->value)
            ->sole();

        $this->assertSame(0, (int) $movement->qty_signed);
        $this->assertSame(600_000, (int) $movement->value_rupiah);
        $this->assertSame(66_000, (int) $movement->unit_cost_rupiah);
        $this->assertSame(LandedCost::class, $movement->reference_type);
        $this->assertSame((string) $landedCost->id, $movement->reference_id);

        $this->assertSame([], app(InventoryValuation::class)->reconcile());
        $this->assertSame([], app(StockLedger::class)->reconcile());
    }

    public function test_the_quantity_on_the_shelf_is_untouched(): void
    {
        // Nothing arrived. Only what it cost changed.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);

        $this->allocate($charge, [$receipt]);

        $this->assertSame(100, (int) StockLevel::query()
            ->where('sku', self::SKU_A)
            ->where('warehouse_id', $this->gudang->id)
            ->value('qty_on_hand'));
    }

    public function test_adding_cost_to_a_sku_with_nothing_on_hand_is_refused(): void
    {
        /*
         * Value with no quantity under it is inventory worth money and holding
         * nothing, and a unit cost of infinity for whatever arrives next. The
         * poster never asks for this — it sends the sold share to HPP instead
         * — but the guard belongs at the bottom, where it cannot be skipped.
         */
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nothing on hand');

        app(StockLedger::class)->addCost(self::SKU_A, $this->gudang->id, 100_000);
    }

    // ---------------------------------------------------------------- the books

    public function test_the_journal_moves_the_charge_out_of_the_clearing_account(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->sell(self::SKU_A, 40);

        $charge = $this->postedCharge(600_000);
        $ledger = app(Ledger::class);

        // Billed, not yet spread: the whole charge is sitting in the queue.
        $this->assertSame(600_000, $ledger->balanceOf(AccountCode::BIAYA_BELUM_DIALOKASIKAN));

        $landedCost = $this->allocate($charge, [$receipt]);

        $entry = JournalEntry::query()
            ->where('source_type', LandedCost::class)
            ->where('source_id', (string) $landedCost->id)
            ->sole();

        $this->assertSame(600_000, (int) $entry->lines()->sum('debit_rupiah'));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::BIAYA_BELUM_DIALOKASIKAN));

        $lines = $entry->lines()->get()->keyBy(fn ($l) => $l->account->kode);

        $this->assertSame(360_000, (int) $lines[AccountCode::PERSEDIAAN]->debit_rupiah);
        $this->assertSame(240_000, (int) $lines[AccountCode::HARGA_POKOK_PENJUALAN]->debit_rupiah);
        $this->assertSame(600_000, (int) $lines[AccountCode::BIAYA_BELUM_DIALOKASIKAN]->kredit_rupiah);
    }

    public function test_every_control_account_still_agrees_afterwards(): void
    {
        /*
         * The check that would catch a wrong posting rule. Persediaan against
         * the moving average is the one this feature could most easily break:
         * uplift the valuation by one figure and post another, and the two
         * drift apart silently.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000], [self::SKU_B, 33, 7_777]]);

        // Shipped through the real order flow rather than the cheap helper:
        // that is what posts the Dr HPP / Cr Persediaan the books need, and a
        // control-account test that skipped it would be checking a fiction.
        $this->shipThroughOrder(self::SKU_A, 41);

        $charge = $this->postedCharge(999_997);
        $this->allocate($charge, [$receipt]);

        $reconciliation = app(LedgerReconciliation::class);

        $this->assertSame([], $reconciliation->discrepancies());
        $this->assertTrue($reconciliation->isClean());
    }

    public function test_a_billed_charge_nobody_has_spread_shows_in_the_clearing_account(): void
    {
        // The account is a worklist. Its balance is the charges still waiting,
        // and the control check says so against the bill lines themselves.
        $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postedCharge(600_000);

        $checks = collect(app(LedgerReconciliation::class)->checks())
            ->keyBy(fn ($check) => $check->kode);

        $check = $checks[AccountCode::BIAYA_BELUM_DIALOKASIKAN];

        $this->assertSame(600_000, $check->buku);
        $this->assertSame(600_000, $check->subledger);
        $this->assertTrue($check->agrees());
    }

    public function test_a_freight_bill_no_longer_lands_in_goods_received_not_invoiced(): void
    {
        /*
         * It used to. A cost line had a null goods_receipt_line_id, which the
         * posting rule read as "billed before delivery" and accrued against
         * Utang Belum Ditagih — where it would sit forever, because no
         * delivery of freight is ever coming. That left a permanent contra
         * balance in the one account whose usefulness depends on being empty.
         */
        $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postedCharge(600_000);

        $ledger = app(Ledger::class);

        $this->assertSame(6_000_000, $ledger->balanceOf(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(600_000, $ledger->balanceOf(AccountCode::BIAYA_BELUM_DIALOKASIKAN));
    }

    public function test_a_voided_bill_takes_its_charge_out_of_the_queue(): void
    {
        /*
         * Voiding the bill removes what we owe, so the charge it carried is no
         * longer waiting to be spread — it no longer exists. Leaving it in the
         * subledger figure would make the clearing account report a permanent
         * drift against a charge nobody can allocate, because the allocator
         * refuses a voided bill.
         */
        $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);

        $checks = fn () => collect(app(LedgerReconciliation::class)->checks())
            ->keyBy(fn ($check) => $check->kode)[AccountCode::BIAYA_BELUM_DIALOKASIKAN];

        $this->assertSame(600_000, $checks()->subledger);

        $charge->supplierBill->forceFill(['status' => 'void'])->save();

        $this->assertSame(0, $checks()->subledger);
    }

    public function test_posting_is_written_to_the_audit_log(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $log = AuditLog::query()->where('action', 'landed_cost_posted')->sole();

        $this->assertSame($this->finance->id, $log->actor_id);
        $this->assertSame($landedCost->nomor, $log->new_value['nomor']);
        $this->assertSame(600_000, $log->new_value['ke_persediaan_rupiah']);
    }

    // ------------------------------------------------------------- refusals

    #[DataProvider('roles')]
    public function test_who_may_allocate_a_charge(Role $role, bool $allowed): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);
        $actor = User::factory()->role($role)->create();

        if (! $allowed) {
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('tidak berhak');
        }

        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $actor);

        $this->assertTrue($landedCost->isDraft());
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    public function test_a_goods_line_cannot_be_allocated_as_a_charge(): void
    {
        // Otherwise the same rupiah lands in inventory twice: once when the
        // goods were received, once as its own freight.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $bill = $this->postedBill([
            fn () => SupplierBillLine::factory()->forReceiptLine($receipt->lines()->first()),
        ], $this->pemasok);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('menagih barang, bukan biaya');

        app(LandedCostAllocator::class)->draw(
            $bill->lines()->first(), [$receipt], AllocationBasis::Nilai, $this->finance
        );
    }

    public function test_the_same_charge_cannot_be_spread_twice(): void
    {
        // Double-counting freight into inventory, and a clearing account that
        // goes negative — visible only to somebody reading the balance sheet.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);

        $this->allocate($charge, [$receipt]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah dialokasikan');

        app(LandedCostAllocator::class)
            ->draw($charge->refresh(), [$receipt], AllocationBasis::Nilai, $this->finance);
    }

    public function test_a_posted_allocation_cannot_be_posted_again(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);
        $landedCost = $this->allocate($charge, [$receipt]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah diposting');

        app(LandedCostPoster::class)->post($landedCost, $this->finance);
    }

    public function test_a_charge_on_an_unposted_bill_is_refused(): void
    {
        // Nothing is owed yet, so there is no cost to spread.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        $bill = SupplierBill::factory()->create(['supplier_id' => $this->forwarder->id]);
        $charge = SupplierBillLine::factory()->biaya(600_000)->create(['supplier_bill_id' => $bill->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('belum diposting');

        app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);
    }

    public function test_a_draft_against_a_voided_bill_is_refused_at_posting(): void
    {
        /*
         * A draft can sit for days, and the bill under it can be voided in the
         * meantime. Posting anyway would put value into stock that nothing
         * owes and drive the clearing account negative.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $charge = $this->postedCharge(600_000);

        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);

        $charge->supplierBill->forceFill(['status' => 'void'])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('dibatalkan');

        app(LandedCostPoster::class)->post($landedCost, $this->finance);
    }

    public function test_an_unposted_receipt_cannot_carry_a_charge(): void
    {
        // No stock arrived, so there is nothing for the freight to land on.
        $charge = $this->postedCharge(600_000);

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('belum diposting');

        app(LandedCostAllocator::class)
            ->draw($charge, [$receipt], AllocationBasis::Nilai, $this->finance);
    }

    public function test_allocating_across_nothing_is_refused(): void
    {
        $charge = $this->postedCharge(600_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pilih dulu');

        app(LandedCostAllocator::class)
            ->draw($charge, [], AllocationBasis::Nilai, $this->finance);
    }

    // --- helpers ------------------------------------------------------------

    /** @param  list<array{0: string, 1: int, 2: int}>  $lines  sku, qty, unit cost */
    private function postedReceipt(array $lines, ?Warehouse $warehouse = null): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => ($warehouse ?? $this->gudang)->id,
            'created_by' => $this->finance->id,
        ]);

        foreach ($lines as $i => [$sku, $qty, $unitCost]) {
            GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
                'goods_receipt_id' => $receipt->id, 'sku' => $sku, 'urutan' => $i + 1,
            ]);
        }

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    /** A posted freight invoice from the forwarder, with one charge line. */
    private function postedCharge(int $amount, string $deskripsi = 'Ongkos angkut laut'): SupplierBillLine
    {
        $bill = $this->postedBill([
            fn () => SupplierBillLine::factory()->biaya($amount, $deskripsi),
        ], $this->forwarder);

        return $bill->lines()->first();
    }

    /** @param  list<callable>  $lineFactories */
    private function postedBill(array $lineFactories, Supplier $supplier): SupplierBill
    {
        $bill = SupplierBill::factory()->create(['supplier_id' => $supplier->id]);

        foreach ($lineFactories as $i => $factory) {
            $factory()->create(['supplier_bill_id' => $bill->id, 'urutan' => $i + 1]);
        }

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        return $bill->refresh();
    }

    private function allocate(
        SupplierBillLine $charge,
        array $receipts,
        AllocationBasis $basis = AllocationBasis::Nilai,
    ): LandedCost {
        $landedCost = app(LandedCostAllocator::class)
            ->draw($charge, $receipts, $basis, $this->finance);

        return app(LandedCostPoster::class)->post($landedCost, $this->finance);
    }

    /**
     * Take stock out, so part of a shipment is already gone.
     *
     * Deliberately the short way round: a real order would drag a company, a
     * price list and four state transitions into tests that are about the
     * arithmetic of a freight charge. It writes an honest stock movement and
     * moves the average exactly as a shipment does, which is all those tests
     * read.
     *
     * It does **not** post the shipment's Dr HPP / Cr Persediaan, so it is no
     * use where the books are what is being checked. Use shipThroughOrder()
     * there.
     */
    private function sell(string $sku, int $qty): void
    {
        app(StockLedger::class)->record(
            sku: $sku,
            warehouseId: $this->gudang->id,
            qtySigned: -$qty,
            reason: MovementReason::Pengiriman,
            actor: $this->finance,
        );
    }

    /** The real thing: an order driven to shipped, books and all. */
    private function shipThroughOrder(string $sku, int $qty): Order
    {
        config()->set('xendit.secret_key', '');

        $sales = User::factory()->sales()->create();
        $warehouseStaff = User::factory()->role(Role::Warehouse)->create();
        $company = Company::factory()->creditLimit(500_000_000)->create(['payment_terms_days' => 30]);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => $sku, 'harga' => 100_000,
        ]);

        $order = Order::factory()->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => $sku, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $sales);
        $machine->confirm($order->refresh(), $sales);
        $machine->awaitPayment($order->refresh(), $sales);
        $machine->markPaid($order->refresh(), ['sumber' => 'test']);
        $machine->ship($order->refresh(), $warehouseStaff);

        return $order->refresh();
    }
}
