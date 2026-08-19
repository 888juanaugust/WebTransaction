<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\PurchaseReturnIssuer;
use App\Domain\Purchasing\PurchaseReturnPoster;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Goods going back to a supplier.
 *
 * The document that was missing from the purchase chain. Without it, a wrong
 * part on a delivery had two bad endings: keep it and pay for it, or write it
 * off as a count variance and go on owing the supplier for goods that are no
 * longer here.
 *
 * Most of what follows is about *when* the return happens relative to the
 * bill, because that is what decides which account it touches. Sending back
 * goods nobody has invoiced unwinds an accrual; sending back goods that have
 * been invoiced reduces a real debt and reverses input VAT. Both are ordinary,
 * both happen on the same delivery, and the wrong one balances perfectly while
 * quietly breaking a control account — which is why every one of these tests
 * ends up looking at Utang Usaha or Utang Belum Ditagih rather than at the
 * document.
 */
class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private const SKU_A = 'YH-RP-1';

    private const SKU_B = 'OS-RP-2';

    private Warehouse $gudang;

    private User $finance;

    private User $owner;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);

        Product::factory()->create(['kode' => self::SKU_A, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        Product::factory()->create(['kode' => self::SKU_B, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
    }

    // ------------------------------------------------- goods nobody billed

    public function test_returning_unbilled_goods_unwinds_the_accrual(): void
    {
        /*
         * The simplest case and the one that has to be exactly right first.
         * 100 arrived at 60,000 and nobody has invoiced them; 30 go straight
         * back on the same truck. The receipt accrued 6,000,000 against Utang
         * Belum Ditagih and this takes 1,800,000 of it off. Utang Usaha is
         * untouched, because we never owed anybody a specific amount.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        $return = $this->postReturn($receipt, [[self::SKU_A, 30]]);

        $this->assertSame(0, (int) $return->nilai_ditagih_rupiah);
        $this->assertSame(1_800_000, (int) $return->nilai_belum_ditagih_rupiah);
        $this->assertSame(0, (int) $return->ppn_rupiah);
        $this->assertSame(1_800_000, (int) $return->nilai_persediaan_rupiah);
        $this->assertSame(0, (int) $return->selisih_rupiah);

        $ledger = app(Ledger::class);
        $this->assertSame(4_200_000, $ledger->balanceOf(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        $this->assertSame(4_200_000, $ledger->balanceOf(AccountCode::PERSEDIAAN));
    }

    public function test_unbilled_goods_leave_no_tax_behind(): void
    {
        // There is no faktur pajak behind goods nobody has invoiced, so there
        // is no input VAT to reverse and no transaction code to record.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        $return = $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(0, (int) $return->ppn_rupiah);
        $this->assertNull($return->kode_transaksi);
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::PPN_MASUKAN));
    }

    // --------------------------------------------------- goods already billed

    public function test_returning_billed_goods_reduces_what_we_owe(): void
    {
        /*
         * The supplier has invoiced the delivery, so the accrual is long
         * cleared and a real debt stands in its place. Sending 30 back has to
         * come off Utang Usaha — including the PPN, which is what the supplier
         * will credit — and leave Utang Belum Ditagih alone.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $bill = $this->postedBillFor($receipt, fakturPajak: true);

        $return = $this->postReturn($receipt, [[self::SKU_A, 30]]);

        $this->assertSame(1_800_000, (int) $return->nilai_ditagih_rupiah);
        $this->assertSame(0, (int) $return->nilai_belum_ditagih_rupiah);
        $this->assertSame(198_000, (int) $return->ppn_rupiah);
        $this->assertSame(1_998_000, (int) $return->total_rupiah);

        $ledger = app(Ledger::class);
        // The bill was 6,000,000 + 660,000 PPN = 6,660,000.
        $this->assertSame(6_660_000 - 1_998_000, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_BELUM_DITAGIH));

        // Input VAT no longer creditable: 660,000 claimed, 198,000 given back.
        $this->assertSame(462_000, $ledger->balanceOf(AccountCode::PPN_MASUKAN));

        $this->assertSame($bill->id, (int) $return->lines()->first()->supplier_bill_id);
    }

    public function test_input_vat_that_was_never_creditable_reverses_where_it_went(): void
    {
        /*
         * A bill with no faktur pajak books its PPN to the non-creditable
         * expense account, not to PPN Masukan — it was never an asset.
         * Reversing it to PPN Masukan anyway would leave that account contra
         * and an expense standing forever, so the reversal has to follow where
         * the money actually went. Both sides name the same constant for that
         * reason; splitting them is how the two accounts drift for good.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postedBillFor($receipt, fakturPajak: false);

        $ledger = app(Ledger::class);
        $this->assertSame(660_000, $ledger->balanceOf(AccountCode::BEBAN_PPN_TIDAK_KREDIT));

        $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(0, $ledger->balanceOf(AccountCode::BEBAN_PPN_TIDAK_KREDIT));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PPN_MASUKAN));

        // And nothing leaked into the general bucket on the way through.
        $this->assertSame(0, $ledger->balanceOf(AccountCode::BEBAN_OPERASIONAL));
    }

    public function test_a_half_billed_delivery_splits_the_return_between_both_accounts(): void
    {
        /*
         * The case the whole design exists for. The supplier invoiced 60 of
         * the 100 that arrived; all 100 go back. Sixty of them reduce a real
         * debt and forty unwind the accrual, and the two figures land in
         * different accounts on the same document.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $line = $receipt->lines()->first();

        $this->postedBill([
            fn () => SupplierBillLine::factory()->forReceiptLine($line, 3_600_000)
                ->state(['qty_base' => 60]),
        ], fakturPajak: true);

        $return = $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(3_600_000, (int) $return->nilai_ditagih_rupiah);
        $this->assertSame(2_400_000, (int) $return->nilai_belum_ditagih_rupiah);
        $this->assertSame(60, (int) $return->lines()->first()->qty_ditagih);

        $ledger = app(Ledger::class);
        // Billed 3,600,000 + 396,000 PPN; all of it returned.
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        // Accrued 6,000,000, cleared 3,600,000 by the bill, 2,400,000 by this.
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PERSEDIAAN));
    }

    public function test_a_return_pays_down_the_debt_before_the_accrual(): void
    {
        /*
         * Billed-first, and the order is not arbitrary. Attribute a partial
         * return to the unbilled half and Utang Belum Ditagih goes contra
         * while an invoice for goods we no longer hold still stands in Utang
         * Usaha — and the next payment run pays it.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $line = $receipt->lines()->first();

        $this->postedBill([
            fn () => SupplierBillLine::factory()->forReceiptLine($line, 3_600_000)
                ->state(['qty_base' => 60]),
        ]);

        // 40 back out of 100, of which 60 are billed: all 40 come off the debt.
        $return = $this->postReturn($receipt, [[self::SKU_A, 40]]);

        $this->assertSame(40, (int) $return->lines()->first()->qty_ditagih);
        $this->assertSame(2_400_000, (int) $return->nilai_ditagih_rupiah);
        $this->assertSame(0, (int) $return->nilai_belum_ditagih_rupiah);
    }

    public function test_a_second_return_does_not_claim_the_same_billed_quantity_twice(): void
    {
        /*
         * Two returns against one half-billed delivery. The first takes the
         * debt down; the second has to know that and unwind the accrual
         * instead, or the payable would be reduced twice for goods that were
         * only ever billed once.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $line = $receipt->lines()->first();

        $this->postedBill([
            fn () => SupplierBillLine::factory()->forReceiptLine($line, 3_600_000)
                ->state(['qty_base' => 60]),
        ]);

        $first = $this->postReturn($receipt, [[self::SKU_A, 60]]);
        $second = $this->postReturn($receipt, [[self::SKU_A, 40]]);

        $this->assertSame(60, (int) $first->lines()->first()->qty_ditagih);
        $this->assertSame(0, (int) $second->lines()->first()->qty_ditagih);
        $this->assertSame(2_400_000, (int) $second->nilai_belum_ditagih_rupiah);
    }

    public function test_a_supplier_who_billed_for_more_than_they_delivered_cannot_be_credited_for_it(): void
    {
        /*
         * Over-billing is the case the three-way match exists for: 120 pieces
         * invoiced against a delivery of 100. Whatever is settled about the
         * extra 20, it is not a return — they were never here to send back —
         * so the returnable line has to cap what it offers at what arrived.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $line = $receipt->lines()->first();

        $this->postedBill([
            fn () => SupplierBillLine::factory()->forReceiptLine($line, 7_200_000)
                ->state(['qty_base' => 120]),
        ]);

        $returnable = app(PurchaseReturnIssuer::class)->returnableFor($receipt->refresh(), $line);

        $this->assertSame(120, $returnable->billedQty);
        $this->assertSame(100, $returnable->remainingBilledQty());
        $this->assertSame([100, 0], $returnable->splitFor(100));
    }

    public function test_a_bill_nobody_has_posted_does_not_make_goods_billed(): void
    {
        /*
         * A draft bill is somebody typing an invoice, not a debt. It has no
         * total, it is in no ledger, and treating what it covers as billed
         * would send a return to Utang Usaha against an amount nobody owes —
         * leaving the accrual it should have unwound standing forever.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $line = $receipt->lines()->first();

        $bill = SupplierBill::factory()->create(['supplier_id' => $this->pemasok->id]);
        SupplierBillLine::factory()->forReceiptLine($line)
            ->create(['supplier_bill_id' => $bill->id, 'urutan' => 1]);

        $return = $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(0, (int) $return->nilai_ditagih_rupiah);
        $this->assertSame(6_000_000, (int) $return->nilai_belum_ditagih_rupiah);
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::UTANG_BELUM_DITAGIH));
    }

    // ------------------------------------------------------------ the stock

    public function test_the_goods_actually_leave_the_shelf(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        $this->postReturn($receipt, [[self::SKU_A, 30]]);

        $level = StockLevel::query()
            ->where('sku', self::SKU_A)
            ->where('warehouse_id', $this->gudang->id)
            ->first();

        $this->assertSame(70, $level->qty_on_hand);
        $this->assertSame(70, app(StockLedger::class)->onHandFromLedger(self::SKU_A, $this->gudang->id));

        $movement = StockMovement::query()
            ->where('reason', MovementReason::ReturPembelian->value)
            ->firstOrFail();

        $this->assertSame(-30, (int) $movement->qty_signed);
        $this->assertSame(PurchaseReturn::class, $movement->reference_type);
    }

    public function test_a_return_cannot_send_back_stock_that_is_no_longer_there(): void
    {
        /*
         * The receipt says what arrived, not what is left. Ninety of the
         * hundred have been sold, so sending back thirty would drive the shelf
         * negative and put Persediaan below what the valuation says it is.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->sell(self::SKU_A, 90);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tersedia 10');

        $this->postReturn($receipt, [[self::SKU_A, 30]]);
    }

    public function test_a_return_cannot_take_stock_promised_to_a_customer(): void
    {
        /*
         * Reserved stock is fenced off for an order somebody has already been
         * told they will get. A return may not quietly consume it: that
         * delivery going back was not promised to anybody, and the order was.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->reserveThroughOrder(self::SKU_A, 80);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tersedia 20');

        $this->postReturn($receipt, [[self::SKU_A, 30]]);
    }

    public function test_a_return_is_not_a_sale(): void
    {
        /*
         * Its own movement reason rather than a shipment. Counting it as one
         * would make cost of sales include goods that were never sold, and
         * make a dead part look like it is moving on the stock report.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postReturn($receipt, [[self::SKU_A, 30]]);

        $this->assertSame(0, app(InventoryValuation::class)->costOfGoodsSold());
        $this->assertSame(
            0,
            StockMovement::query()->where('reason', MovementReason::Pengiriman->value)->count(),
        );
    }

    // ----------------------------------------------- the average has moved

    public function test_stock_leaves_at_the_average_and_the_difference_is_a_real_loss(): void
    {
        /*
         * 100 in at 60,000, then 100 more at 80,000: the average is 70,000.
         * Returning 50 of the first delivery takes 3,500,000 off the shelf,
         * but the supplier only credits what they charged — 3,000,000. The
         * 500,000 difference is not a rounding artefact and it is not a plug;
         * it is what moving-average costing actually does, and it belongs in
         * the account that already means "carried at one figure, settled at
         * another".
         */
        $first = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postedReceipt([[self::SKU_A, 100, 80_000]]);

        $this->assertSame(70_000, app(InventoryValuation::class)->unitCost(self::SKU_A));

        $return = $this->postReturn($first, [[self::SKU_A, 50]]);

        $this->assertSame(3_500_000, (int) $return->nilai_persediaan_rupiah);
        $this->assertSame(3_000_000, (int) $return->nilai_belum_ditagih_rupiah);
        $this->assertSame(500_000, (int) $return->selisih_rupiah);

        $ledger = app(Ledger::class);
        $this->assertSame(500_000, $ledger->balanceOf(AccountCode::SELISIH_HARGA_PEMBELIAN));
        $this->assertTrue($ledger->isBalanced());
    }

    public function test_a_supplier_who_overbilled_gets_their_variance_back(): void
    {
        /*
         * The bill charged more than the goods were received at, so posting it
         * put the difference in Selisih Harga Pembelian rather than rewriting
         * stock value. Returning everything has to unwind that too, or the
         * variance stands against goods we no longer have.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $line = $receipt->lines()->first();

        // Billed 6,500,000 for goods received at 6,000,000.
        $this->postedBill([fn () => SupplierBillLine::factory()->forReceiptLine($line, 6_500_000)]);

        $ledger = app(Ledger::class);
        $this->assertSame(500_000, $ledger->balanceOf(AccountCode::SELISIH_HARGA_PEMBELIAN));

        $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(0, $ledger->balanceOf(AccountCode::SELISIH_HARGA_PEMBELIAN));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PERSEDIAAN));
    }

    // ------------------------------------------------- the control accounts

    public function test_the_books_still_agree_with_the_subledgers(): void
    {
        /*
         * The check that would catch a wrong posting rule. A trial balance
         * proves the entry balances; only this proves it went to the right
         * accounts.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000], [self::SKU_B, 50, 40_000]]);
        $line = $receipt->lines()->firstWhere('sku', self::SKU_A);

        $this->postedBill([fn () => SupplierBillLine::factory()->forReceiptLine($line)]);

        $this->postReturn($receipt, [[self::SKU_A, 40], [self::SKU_B, 20]]);

        $reconciliation = app(LedgerReconciliation::class);

        $this->assertSame([], $reconciliation->discrepancies());
        $this->assertTrue($reconciliation->isClean());
    }

    public function test_what_we_owe_a_supplier_drops_without_the_bill_being_touched(): void
    {
        /*
         * CLAUDE.md's hard rule read from the purchase side: nobody may edit
         * the amount on a bill, not even to record a return. So the bill's
         * total stands and the subledger gains a third term — and if that term
         * is forgotten, Utang Usaha drifts from it every time a delivery goes
         * back.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $bill = $this->postedBillFor($receipt);

        $return = $this->postReturn($receipt, [[self::SKU_A, 25]]);

        $suppliers = app(SupplierLedger::class);

        $this->assertSame(6_660_000, (int) $bill->refresh()->total_rupiah);
        $this->assertSame(6_660_000 - (int) $return->total_rupiah, $suppliers->outstandingFor($this->pemasok));
        $this->assertSame($suppliers->outstandingFor($this->pemasok), $suppliers->totalPayable());
    }

    public function test_a_draft_return_changes_nothing(): void
    {
        // A draft is an intention. Letting one reduce a payable would take an
        // invoice out of the payment run on the strength of nobody's decision.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postedBillFor($receipt);

        $return = $this->draftReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(6_660_000, app(SupplierLedger::class)->totalPayable());
        $this->assertSame(0, (int) $return->total_rupiah);
        $this->assertTrue(app(LedgerReconciliation::class)->isClean());
    }

    public function test_a_bill_fully_returned_stops_being_chased(): void
    {
        /*
         * The one thing this document most has to prevent: paying for goods
         * that went back. A bill with nothing left owing on it must leave the
         * overdue queue, and status has to follow the arithmetic rather than
         * whoever acted last.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $bill = $this->postedBillFor($receipt);

        $bill->forceFill(['due_date' => now()->subDays(10)->toDateString()])->save();
        $this->assertSame(1, SupplierBill::query()->overdue()->count());

        $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(0, $bill->refresh()->amountOutstanding());
        $this->assertSame(SupplierBill::STATUS_PAID, $bill->status);
        $this->assertSame(0, SupplierBill::query()->overdue()->count());
    }

    public function test_a_partial_return_leaves_the_rest_of_the_bill_owing(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $bill = $this->postedBillFor($receipt);

        $return = $this->postReturn($receipt, [[self::SKU_A, 25]]);

        $this->assertSame(6_660_000 - (int) $return->total_rupiah, $bill->refresh()->amountOutstanding());
        $this->assertSame(SupplierBill::STATUS_OPEN, $bill->status);
    }

    // ------------------------------------------------------- what it refuses

    public function test_nobody_may_return_more_than_arrived(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('melebihi yang pernah diterima');

        $this->postReturn($receipt, [[self::SKU_A, 101]]);
    }

    public function test_two_returns_cannot_send_back_the_same_carton(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postReturn($receipt, [[self::SKU_A, 70]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tersisa 30');

        $this->postReturn($receipt, [[self::SKU_A, 40]]);
    }

    public function test_a_return_cannot_be_posted_twice(): void
    {
        // Two people hitting Posting on the same draft is what happens when
        // the first click looks slow. Twice would take the goods off the shelf
        // twice and credit us twice.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $return = $this->postReturn($receipt, [[self::SKU_A, 30]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah diposting');

        app(PurchaseReturnPoster::class)->post($return, $this->finance);
    }

    public function test_a_draft_receipt_has_nothing_to_send_back(): void
    {
        // Nothing is on the shelf and nothing is in the books. Correct the
        // receipt instead — it is still editable, which is the whole
        // difference between the two states.
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('belum diposting');

        app(PurchaseReturnIssuer::class)->draft($receipt, $this->finance, 'Salah kirim');
    }

    public function test_a_return_must_say_why(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('alasannya');

        app(PurchaseReturnIssuer::class)->draft($receipt, $this->finance, '   ');
    }

    public function test_an_empty_return_is_not_a_document(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);

        $return = app(PurchaseReturnIssuer::class)
            ->draft($receipt, $this->finance, 'Salah kirim');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak punya baris');

        app(PurchaseReturnPoster::class)->post($return, $this->finance);
    }

    public function test_a_line_billed_across_two_bills_is_refused_rather_than_guessed_at(): void
    {
        /*
         * Apportioning a credit between two invoices is a guess about which
         * one the supplier means to credit, and a wrong guess leaves one bill
         * chased for goods that went back while the other is under-paid.
         * Rare enough to refuse out loud.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $line = $receipt->lines()->first();

        $this->postedBill([
            fn () => SupplierBillLine::factory()->forReceiptLine($line, 3_000_000)->state(['qty_base' => 50]),
        ]);
        $this->postedBill([
            fn () => SupplierBillLine::factory()->forReceiptLine($line, 3_000_000)->state(['qty_base' => 50]),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('lebih dari satu tagihan');

        $this->postReturn($receipt, [[self::SKU_A, 10]]);
    }

    public function test_a_voided_bill_leaves_the_goods_unbilled_again(): void
    {
        /*
         * A voided bill was never owed, so what it covered is unbilled — and
         * what still stands is the receipt's accrual. Netting the void instead
         * of excluding it would take a return off a payable that no longer
         * exists.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $bill = $this->postedBillFor($receipt);

        $bill->forceFill(['status' => SupplierBill::STATUS_VOID])->save();

        $return = $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->assertSame(0, (int) $return->nilai_ditagih_rupiah);
        $this->assertSame(6_000_000, (int) $return->nilai_belum_ditagih_rupiah);
        $this->assertNull($return->lines()->first()->supplier_bill_id);
    }

    // -------------------------------------------------------------- access

    public function test_sales_may_not_send_goods_back_to_a_supplier(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $sales = User::factory()->sales()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak berhak');

        app(PurchaseReturnIssuer::class)->draft($receipt, $sales, 'Salah kirim');
    }

    public function test_warehouse_may_not_post_a_return(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $return = $this->draftReturn($receipt, [[self::SKU_A, 10]]);
        $warehouse = User::factory()->role(Role::Warehouse)->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak berhak');

        app(PurchaseReturnPoster::class)->post($return, $warehouse);
    }

    public function test_posting_is_written_to_the_audit_log(): void
    {
        // Every money-affecting action writes to the audit log, and this one
        // writes stock off a shelf on somebody's say-so.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $return = $this->postReturn($receipt, [[self::SKU_A, 30]]);

        $entry = AuditLog::query()->where('action', 'purchase_return_posted')->firstOrFail();

        $this->assertSame($this->finance->id, $entry->actor_id);
        $this->assertSame($return->alasan, $entry->alasan);
        $this->assertSame(30, $entry->new_value['qty_base']);
    }

    // ------------------------------------------------------- drawing it up

    public function test_a_draft_can_be_drawn_with_everything_still_returnable_on_it(): void
    {
        /*
         * The common case: a delivery turns up wrong and the lot goes back on
         * the same truck. Starting from everything and deleting what stays is
         * the version where forgetting a line leaves you having returned too
         * much rather than having quietly kept goods you were not billed for.
         */
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000], [self::SKU_B, 50, 40_000]]);

        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt, $this->finance, 'Salah kirim semua');

        $this->assertSame(2, $return->lines()->count());
        $this->assertSame(150, $return->qtyReturned());
        $this->assertSame($this->pemasok->id, $return->supplier_id);
        $this->assertSame($this->gudang->id, $return->warehouse_id);
    }

    public function test_drawing_it_up_again_offers_only_what_is_left(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postReturn($receipt, [[self::SKU_A, 70]]);

        $return = app(PurchaseReturnIssuer::class)
            ->draftEverything($receipt, $this->finance, 'Sisanya juga salah');

        $this->assertSame(30, $return->qtyReturned());
    }

    public function test_a_fully_returned_delivery_cannot_be_drawn_from_again(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah diretur');

        app(PurchaseReturnIssuer::class)->draft($receipt, $this->finance, 'Lagi');
    }

    public function test_a_fully_returned_line_stays_on_the_list_showing_nothing_left(): void
    {
        // "Why can I not send this back" is a question the screen should
        // answer, and a missing row answers nothing.
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000], [self::SKU_B, 50, 40_000]]);
        $this->postReturn($receipt, [[self::SKU_A, 100]]);

        $lines = app(PurchaseReturnIssuer::class)->returnable($receipt->refresh());

        $this->assertCount(2, $lines);
        $this->assertTrue($lines[0]->isFullyReturned());
        $this->assertSame(0, $lines[0]->remainingQty());
        $this->assertSame(50, $lines[1]->remainingQty());
    }

    // ------------------------------------------------------------ the entry

    public function test_the_journal_entry_names_the_document_and_balances(): void
    {
        $receipt = $this->postedReceipt([[self::SKU_A, 100, 60_000]]);
        $this->postedBillFor($receipt);

        $return = $this->postReturn($receipt, [[self::SKU_A, 30]]);

        $entry = JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_RETUR_PEMBELIAN)
            ->firstOrFail();

        $this->assertSame(PurchaseReturn::class, $entry->source_type);
        $this->assertSame($return->id, (int) $entry->source_id);
        $this->assertStringContainsString($return->nomor, $entry->keterangan);
        $this->assertTrue(app(Ledger::class)->isBalanced());
    }

    // --- helpers ------------------------------------------------------------

    /** @param  list<array{0: string, 1: int, 2: int}>  $lines */
    private function postedReceipt(array $lines): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
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

    /** A posted bill covering every line of a receipt at what it was received at. */
    private function postedBillFor(GoodsReceipt $receipt, bool $fakturPajak = true): SupplierBill
    {
        return $this->postedBill(
            $receipt->lines->map(
                fn (GoodsReceiptLine $line) => fn () => SupplierBillLine::factory()->forReceiptLine($line),
            )->all(),
            $fakturPajak,
        );
    }

    /** @param  list<callable>  $lineFactories */
    private function postedBill(array $lineFactories, bool $fakturPajak = true): SupplierBill
    {
        $bill = SupplierBill::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'nomor_faktur_pajak' => $fakturPajak ? '010.000-26.'.fake()->unique()->numerify('########') : null,
        ]);

        foreach ($lineFactories as $i => $factory) {
            $factory()->create(['supplier_bill_id' => $bill->id, 'urutan' => $i + 1]);
        }

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        return $bill->refresh();
    }

    /** @param  list<array{0: string, 1: int}>  $lines */
    private function draftReturn(GoodsReceipt $receipt, array $lines): PurchaseReturn
    {
        $return = app(PurchaseReturnIssuer::class)
            ->draft($receipt, $this->finance, 'Barang tidak sesuai pesanan');

        foreach ($lines as $i => [$sku, $qty]) {
            PurchaseReturnLine::create([
                'purchase_return_id' => $return->id,
                'goods_receipt_line_id' => $receipt->lines->firstWhere('sku', $sku)->id,
                'sku' => $sku,
                'urutan' => $i + 1,
                'qty_base' => $qty,
                'ordered_qty' => $qty,
            ]);
        }

        return $return->refresh();
    }

    /** @param  list<array{0: string, 1: int}>  $lines */
    private function postReturn(GoodsReceipt $receipt, array $lines): PurchaseReturn
    {
        return app(PurchaseReturnPoster::class)
            ->post($this->draftReturn($receipt, $lines), $this->finance);
    }

    /**
     * Take stock out, so part of a delivery is already gone.
     *
     * The short way round on purpose: a real order would drag a company, a
     * price list and four transitions into a test that is about whether a
     * shelf has enough on it.
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

    /** Fence stock off against a confirmed order, the way a real promise does. */
    private function reserveThroughOrder(string $sku, int $qty): Order
    {
        $sales = User::factory()->sales()->create();
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

        return $order->refresh();
    }
}
