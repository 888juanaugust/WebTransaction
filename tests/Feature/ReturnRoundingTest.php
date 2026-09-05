<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Billing\CreditNoteIssuer;
use App\Domain\Billing\CreditNotePoster;
use App\Domain\Billing\CreditNoteType;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\PurchaseReturnIssuer;
use App\Domain\Purchasing\PurchaseReturnPoster;
use App\Domain\Stock\InventoryValuation;
use App\Models\Company;
use App\Models\CreditNoteLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Goods sent back in instalments must give back exactly what they took out.
 *
 * Both return paths apportioned a frozen total by dividing the *original*
 * figure afresh on every note. Moving-average cost almost never divides evenly
 * by quantity, so the same fraction rounded up again and again:
 *
 *     bought 7 for Rp 80.000, shipped all 7, returned all 7 one at a time
 *     → the shelf held those 7 units at Rp 80.003
 *
 * Rp 3 of asset value conjured out of rounding, cost of sales short by the
 * same, and **nothing in the system could see it**. The stock movement is
 * recorded with the very figure the journal posts, so the general ledger and
 * the costing subledger agreed with each other perfectly — and the
 * control-account check compares exactly those two. It had no third opinion.
 * That is what makes this worth more than its size suggests: it is not a
 * number somebody would eventually query, it is a number that accumulates
 * quietly, one partial return at a time, for as long as the business runs.
 *
 * The fix is the rule `Money::allocate` applies to a split known all at once,
 * carried across time instead: apportion **what is left**, so the final note
 * settles the difference by construction. The tests below are written as that
 * property rather than as the arithmetic — returning a line in pieces gives
 * back what returning it whole would — so they keep holding if the rounding
 * rule ever changes.
 */
class ReturnRoundingTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'RD-1';

    private User $finance;

    private User $sales;

    private User $inventori;

    private User $owner;

    private Warehouse $gudang;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->sales = User::factory()->sales()->create();
        $this->inventori = User::factory()->role(Role::Warehouse)->create();
        $this->owner = User::factory()->owner()->create();
        $this->gudang = Warehouse::factory()->create();
        $this->pemasok = Supplier::factory()->create();

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);
        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subYear()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);
    }

    /**
     * Two receipts at different unit costs, so the moving average is not a
     * whole rupiah. This is the ordinary case for a wholesaler restocking at
     * a new price, not a contrived one: 3 at 10.000 and 4 at 12.500 is
     * 7 units for 80.000, or 11.428,57 each.
     */
    private function stockAtAnAwkwardAverage(): void
    {
        $this->receive(3, 10_000);
        $this->receive(4, 12_500);
    }

    private function receive(int $qty, int $unitCost): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, $unitCost)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    private function shipped(int $qty): Invoice
    {
        $company = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'Bengkel Retur', 'status' => Company::STATUS_ACTIVE,
        ]);

        $order = Order::factory()->create([
            'company_id' => $company->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $m = app(OrderStateMachine::class);
        $m->submit($order->refresh(), $this->sales);
        $m->confirm($order->refresh(), $this->owner);
        $m->awaitPayment($order->refresh(), $this->sales);
        $m->markPaid($order->refresh(), ['invoice_id' => $order->refresh()->invoice->id]);
        $m->ship($order->refresh(), User::factory()->storage((int) $this->gudang->id)->create());

        return $order->refresh()->invoice()->firstOrFail();
    }

    /** One customer return of `$qty`, filed and verified by two people. */
    private function returnToUs(Invoice $invoice, OrderLine $line, int $qty): void
    {
        $note = app(CreditNoteIssuer::class)->draft(
            $invoice->refresh(), CreditNoteType::ReturBarang, $this->owner,
            'Retur sebagian', warehouseId: $this->gudang->id,
        );
        CreditNoteLine::factory()->create([
            'credit_note_id' => $note->id,
            'order_line_id' => $line->id,
            'sku' => self::SKU, 'urutan' => 1, 'qty_base' => $qty,
        ]);

        app(CreditNotePoster::class)->post($note->refresh(), $this->inventori);
    }

    private function nilaiPersediaan(): int
    {
        return (int) app(InventoryValuation::class)->totalValue();
    }

    private function glPersediaan(): int
    {
        return app(Ledger::class)->balanceOf(AccountCode::PERSEDIAAN);
    }

    // --- the customer sending goods back -------------------------------------

    public function test_returning_a_line_one_unit_at_a_time_gives_back_what_shipped(): void
    {
        $this->stockAtAnAwkwardAverage();

        $invoice = $this->shipped(7);
        $line = $invoice->order->lines()->firstOrFail();

        $this->assertSame(0, $this->nilaiPersediaan(), 'everything shipped');
        $this->assertSame(0, $this->glPersediaan());

        for ($i = 0; $i < 7; $i++) {
            $this->returnToUs($invoice, $line, 1);
        }

        // Exactly what the goods cost, not a rupiah more. Before this, the
        // seven notes put back Rp 80.003.
        $this->assertSame(80_000, $this->nilaiPersediaan());
        $this->assertSame(80_000, $this->glPersediaan());
    }

    /**
     * The property, stated so it survives a change of rounding rule: the
     * pieces and the whole must agree.
     */
    public function test_the_pieces_agree_with_the_whole(): void
    {
        $this->stockAtAnAwkwardAverage();

        $invoice = $this->shipped(7);
        $line = $invoice->order->lines()->firstOrFail();

        $sekaligus = app(CreditNoteIssuer::class)
            ->creditableFor($invoice, $line)
            ->costFor(7);

        $satuan = 0;

        for ($i = 0; $i < 7; $i++) {
            $creditable = app(CreditNoteIssuer::class)->creditableFor($invoice->refresh(), $line);
            $satuan += $creditable->costFor(1);
            $this->returnToUs($invoice, $line, 1);
        }

        $this->assertSame($sekaligus, $satuan);
    }

    /** Uneven instalments, which is what really happens. */
    public function test_uneven_instalments_still_add_up(): void
    {
        $this->stockAtAnAwkwardAverage();

        $invoice = $this->shipped(7);
        $line = $invoice->order->lines()->firstOrFail();

        foreach ([3, 1, 2, 1] as $qty) {
            $this->returnToUs($invoice, $line, $qty);
        }

        $this->assertSame(80_000, $this->nilaiPersediaan());
        $this->assertSame(80_000, $this->glPersediaan());
    }

    /** A partial return leaves the rest still owed to the shelf, not rounded. */
    public function test_a_partial_return_leaves_the_remainder_intact(): void
    {
        $this->stockAtAnAwkwardAverage();

        $invoice = $this->shipped(7);
        $line = $invoice->order->lines()->firstOrFail();

        $this->returnToUs($invoice, $line, 3);

        $sisa = app(CreditNoteIssuer::class)->creditableFor($invoice->refresh(), $line);

        $this->assertSame(4, $sisa->remainingQty());
        $this->assertSame(80_000 - $this->nilaiPersediaan(), $sisa->remainingCostRupiah());
    }

    // --- us sending goods back to the supplier -------------------------------

    public function test_returning_a_delivery_in_pieces_unwinds_the_whole_accrual(): void
    {
        /*
         * The purchase side's own docblock already named this failure —
         * "returning everything would then leave a few rupiah of residue
         * behind in inventory forever" — while guarding only the round trip
         * through a unit cost, not the repetition.
         *
         * 7 pieces for Rp 80.000 is Rp 11.428,57 each. Nobody bills us, so
         * the whole thing sits in Utang Belum Ditagih until it goes back.
         */
        $receipt = $this->receiveAwkward(7, 80_000);

        $this->assertSame(80_000, $this->glPersediaan());
        $this->assertSame(80_000, app(Ledger::class)->balanceOf(AccountCode::UTANG_BELUM_DITAGIH));

        for ($i = 0; $i < 7; $i++) {
            $this->returnToSupplier($receipt, 1);
        }

        $this->assertSame(0, $this->glPersediaan(), 'the shelf is empty and worth nothing');
        $this->assertSame(0, app(Ledger::class)->balanceOf(AccountCode::UTANG_BELUM_DITAGIH));
        $this->assertSame(0, $this->nilaiPersediaan());
    }

    /** A delivery received at one awkward total, in one line. */
    private function receiveAwkward(int $qty, int $totalValue): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);

        GoodsReceiptLine::factory()->pieces($qty, 1)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
            'line_value_rupiah' => $totalValue,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        return $receipt->refresh();
    }

    private function returnToSupplier(GoodsReceipt $receipt, int $qty): PurchaseReturn
    {
        $return = app(PurchaseReturnIssuer::class)
            ->draft($receipt->refresh(), $this->finance, 'Barang tidak sesuai pesanan');

        PurchaseReturnLine::factory()->create([
            'purchase_return_id' => $return->id,
            'goods_receipt_line_id' => $receipt->lines()->first()->id,
            'sku' => self::SKU, 'urutan' => 1, 'qty_base' => $qty,
        ]);

        return app(PurchaseReturnPoster::class)->post($return->refresh(), $this->finance);
    }
}
