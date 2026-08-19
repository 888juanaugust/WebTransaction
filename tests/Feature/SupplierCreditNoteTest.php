<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Purchasing\SupplierCreditNoteIssuer;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Stock\InventoryValuation;
use App\Models\Account;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierCreditNote;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A supplier's credit note: the payable falls, and nothing came off the shelf.
 *
 * The instrument the purchase chain was missing. The three-way match already
 * flags a supplier billing more than the goods were received at; until now the
 * only way to settle that was a purchase return, which takes stock off the
 * shelf that never left. So a pure price dispute could only be resolved by
 * pretending goods went back, or by netting it off a later payment — leaving a
 * payment figure nobody could reconcile afterwards.
 *
 * The distinction against a return is what most of these tests are about:
 * stock must not move, and Utang Usaha must still tie.
 */
class SupplierCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-NKP-1';

    private Warehouse $gudang;

    private User $finance;

    private Supplier $pemasok;

    private SupplierCreditNoteIssuer $issuer;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);

        $this->issuer = app(SupplierCreditNoteIssuer::class);
        $this->ledger = app(Ledger::class);
    }

    public function test_it_reduces_the_payable_and_leaves_the_stock_alone(): void
    {
        /*
         * The whole reason this exists. The supplier agreed the price was
         * wrong; the cartons are still on the shelf. A purchase return would
         * have been the only instrument, and it would have taken 100 pieces
         * off stock that nobody moved.
         */
        $bill = $this->billedReceipt(100, 60_000);

        $stockBefore = app(InventoryValuation::class)->totalValue();

        $note = $this->terbitkan($this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill));

        $this->assertSame(500_000, (int) $note->total_rupiah);
        $this->assertSame(
            $bill->total_rupiah - 500_000,
            $this->ledger->balanceOf(AccountCode::UTANG_USAHA),
        );

        // Not one rupiah of stock moved.
        $this->assertSame($stockBefore, app(InventoryValuation::class)->totalValue());
        $this->assertSame($stockBefore, $this->ledger->balanceOf(AccountCode::PERSEDIAAN));
    }

    public function test_it_unwinds_the_variance_the_bill_created(): void
    {
        /*
         * The ordinary case end to end: goods received at 60,000, billed at
         * 65,000, and the supplier later agrees the 5,000 was wrong. The bill
         * put 500,000 into Selisih Harga Pembelian; crediting the same account
         * takes it straight back out, which is what "the price was corrected"
         * actually means in the books.
         */
        $receipt = $this->postedReceipt(100, 60_000);
        $bill = $this->postedBillAt($receipt, 65_000);

        $this->assertSame(500_000, $this->ledger->balanceOf(AccountCode::SELISIH_HARGA_PEMBELIAN));

        $this->terbitkan($this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill));

        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::SELISIH_HARGA_PEMBELIAN));
    }

    public function test_ppn_comes_off_only_against_a_faktur_pajak_retur(): void
    {
        $bill = $this->billedReceipt(100, 60_000);

        $this->terbitkan($this->draft(
            500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill, ppn: 55_000,
        ));

        // The bill credited 660,000 of input VAT; 55,000 goes back.
        $this->assertSame(660_000 - 55_000, $this->ledger->balanceOf(AccountCode::PPN_MASUKAN));
        $this->assertSame(555_000, SupplierCreditNote::query()->sole()->total_rupiah);
    }

    public function test_without_a_faktur_retur_no_input_vat_moves(): void
    {
        /*
         * There is nothing to take back. Input tax on a bill with no faktur was
         * never credited in the first place — it went straight to expense — so
         * reversing it here would credit an asset that was never raised.
         */
        $bill = $this->billedReceipt(100, 60_000);

        $before = $this->ledger->balanceOf(AccountCode::PPN_MASUKAN);

        $this->terbitkan($this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill));

        $this->assertSame($before, $this->ledger->balanceOf(AccountCode::PPN_MASUKAN));
        $this->assertFalse(SupplierCreditNote::query()->sole()->ada_faktur_pajak_retur);

        /*
         * And no line for it at all, not merely a line for nil. A zero-value
         * journal line balances perfectly and says something happened; an
         * accountant reading the entry has to work out that it did not.
         */
        $entry = JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_NOTA_KREDIT_PEMASOK)
            ->sole();

        $ppnAccount = Account::byCode(AccountCode::PPN_MASUKAN);

        $this->assertSame(2, $entry->lines()->count());
        $this->assertFalse($entry->lines()->where('account_id', $ppnAccount->id)->exists());
    }

    public function test_a_header_account_cannot_be_credited(): void
    {
        // `2-0000 KEWAJIBAN` is a group heading, not a place money goes.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/akun induk/');

        $this->draft(500_000, '2-0000');
    }

    // ------------------------------------------------------- staying tied

    public function test_the_payable_control_account_still_ties(): void
    {
        $bill = $this->billedReceipt(100, 60_000);

        $this->terbitkan($this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill, ppn: 55_000));

        foreach (app(LedgerReconciliation::class)->discrepancies() as $check) {
            $this->fail(sprintf(
                '%s (%s) is out by %s', $check->nama, $check->kode, $check->selisih(),
            ));
        }

        $this->assertSame(
            $this->ledger->balanceOf(AccountCode::UTANG_USAHA),
            app(SupplierLedger::class)->totalPayable(),
        );
    }

    public function test_what_the_supplier_is_owed_comes_down_too(): void
    {
        // The register and the ledger have to move together, or the AP list
        // shows a supplier owed money the books say was already settled.
        $bill = $this->billedReceipt(100, 60_000);
        $before = app(SupplierLedger::class)->outstandingFor($this->pemasok);

        $this->terbitkan($this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill));

        $this->assertSame(
            $before - 500_000,
            app(SupplierLedger::class)->outstandingFor($this->pemasok),
        );
    }

    public function test_a_draft_reduces_nothing(): void
    {
        /*
         * A note somebody typed while querying it on the phone is not an
         * agreement. Letting a draft reduce a payable would show a debt as
         * settled on the strength of a conversation.
         */
        $bill = $this->billedReceipt(100, 60_000);
        $before = app(SupplierLedger::class)->outstandingFor($this->pemasok);

        $this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill);

        $this->assertSame($before, app(SupplierLedger::class)->outstandingFor($this->pemasok));
        $this->assertSame($before, $this->ledger->balanceOf(AccountCode::UTANG_USAHA));
    }

    // ------------------------------------------------------- what it refuses

    public function test_a_credit_cannot_exceed_what_is_left_on_the_bill(): void
    {
        /*
         * A mistyped figure — an extra zero — would turn the payable negative,
         * which reads as the supplier owing us money and gets netted off the
         * next real payment by somebody who cannot see where it came from.
         */
        $bill = $this->billedReceipt(100, 60_000);

        $note = $this->draft(99_000_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/melebihi sisa tagihan/');

        $this->issuer->post($note, $this->finance);
    }

    public function test_without_a_bill_it_is_checked_against_the_whole_payable(): void
    {
        // A blanket rebate belongs to no single bill, so the ceiling is what
        // the supplier is owed altogether.
        $this->billedReceipt(100, 60_000);

        $note = $this->draft(99_000_000, AccountCode::SELISIH_HARGA_PEMBELIAN);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/melebihi total utang/');

        $this->issuer->post($note, $this->finance);
    }

    public function test_the_ceiling_is_checked_when_it_posts_not_when_it_is_typed(): void
    {
        /*
         * A note can sit in draft for a week while somebody queries it, and in
         * that week a payment may have cleared the bill. What the supplier
         * still owes has to be true at the moment the books move.
         */
        $bill = $this->billedReceipt(100, 60_000);

        $note = $this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill);

        app(SupplierLedger::class)->recordPayment(
            $this->pemasok, (int) $bill->total_rupiah, $this->finance, $bill,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/melebihi sisa tagihan/');

        $this->issuer->post($note->refresh(), $this->finance);
    }

    public function test_stock_value_cannot_be_moved_by_hand(): void
    {
        /*
         * The guard that protects the inventory tie. Persediaan moves only
         * through the stock ledger at the cost frozen on each movement; a
         * hand-entered credit would put the account out against the valuation
         * permanently, with no document able to explain it.
         */
        $bill = $this->billedReceipt(100, 60_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/buku stok/');

        $this->draft(500_000, AccountCode::PERSEDIAAN, $bill);
    }

    public function test_the_payable_cannot_be_both_sides_of_the_entry(): void
    {
        $bill = $this->billedReceipt(100, 60_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sisi satunya/');

        $this->draft(500_000, AccountCode::UTANG_USAHA, $bill);
    }

    public function test_a_bill_belonging_to_another_supplier_is_refused(): void
    {
        // Crediting one supplier against another's bill balances perfectly and
        // leaves both accounts wrong.
        $bill = $this->billedReceipt(100, 60_000);
        $lain = Supplier::factory()->create(['nama' => 'PT Pemasok Lain']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/bukan milik/');

        $this->issuer->draft(
            supplier: $lain,
            tanggal: Carbon::now(),
            accountCode: AccountCode::SELISIH_HARGA_PEMBELIAN,
            dasarRupiah: 500_000,
            alasan: 'Koreksi harga',
            actor: $this->finance,
            bill: $bill,
        );
    }

    public function test_nothing_and_no_reason_are_refused(): void
    {
        $this->assertRefused(fn () => $this->draft(0, AccountCode::SELISIH_HARGA_PEMBELIAN));
        $this->assertRefused(fn () => $this->draft(-500_000, AccountCode::SELISIH_HARGA_PEMBELIAN));
        $this->assertRefused(fn () => $this->issuer->draft(
            supplier: $this->pemasok,
            tanggal: Carbon::now(),
            accountCode: AccountCode::SELISIH_HARGA_PEMBELIAN,
            dasarRupiah: 500_000,
            alasan: '   ',
            actor: $this->finance,
        ));
    }

    public function test_posting_twice_is_refused(): void
    {
        $bill = $this->billedReceipt(100, 60_000);
        $note = $this->terbitkan($this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah diposting/');

        $this->issuer->post($note, $this->finance);
    }

    public function test_a_posted_note_cannot_be_discarded(): void
    {
        $bill = $this->billedReceipt(100, 60_000);
        $note = $this->terbitkan($this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN, $bill));

        $this->assertRefused(fn () => $this->issuer->discard($note, $this->finance));
        $this->assertSame(1, SupplierCreditNote::query()->count());
    }

    public function test_a_draft_can_be_thrown_away(): void
    {
        $note = $this->draft(500_000, AccountCode::SELISIH_HARGA_PEMBELIAN);

        $this->issuer->discard($note, $this->finance);

        $this->assertSame(0, SupplierCreditNote::query()->count());
    }

    #[DataProvider('roles')]
    public function test_who_may_record_one(Role $role, bool $allowed): void
    {
        $actor = User::factory()->role($role)->create();

        try {
            $this->issuer->draft(
                supplier: $this->pemasok,
                tanggal: Carbon::now(),
                accountCode: AccountCode::SELISIH_HARGA_PEMBELIAN,
                dasarRupiah: 500_000,
                alasan: 'Koreksi harga',
                actor: $actor,
            );
            $this->assertTrue($allowed, "{$role->value} should not have been allowed");
        } catch (DomainException $e) {
            $this->assertFalse($allowed, $e->getMessage());
            $this->assertStringContainsString('tidak berhak', $e->getMessage());
        }
    }

    public static function roles(): array
    {
        return [
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
            // It reduces what we owe on the strength of a supplier's paper,
            // and purchase cost is not Sales' business.
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    // --- helpers ------------------------------------------------------------

    private function draft(
        int $dasar,
        string $account,
        ?SupplierBill $bill = null,
        int $ppn = 0,
    ): SupplierCreditNote {
        return $this->issuer->draft(
            supplier: $this->pemasok,
            tanggal: Carbon::now(),
            accountCode: $account,
            dasarRupiah: $dasar,
            alasan: 'Koreksi harga yang disepakati',
            actor: $this->finance,
            bill: $bill,
            ppnRupiah: $ppn,
            nomorNotaSupplier: 'CN-2026-0091',
        );
    }

    private function terbitkan(SupplierCreditNote $note): SupplierCreditNote
    {
        return $this->issuer->post($note, $this->finance);
    }

    private function postedReceipt(int $qty, int $unitCost): GoodsReceipt
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

    /** A bill covering the receipt at the price it was received at. */
    private function billedReceipt(int $qty, int $unitCost): SupplierBill
    {
        return $this->postedBillAt($this->postedReceipt($qty, $unitCost), $unitCost);
    }

    /** A bill covering a receipt at a possibly different price. */
    private function postedBillAt(GoodsReceipt $receipt, int $unitCost): SupplierBill
    {
        $bill = SupplierBill::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'nomor_faktur_pajak' => '010.000-26.'.fake()->unique()->numerify('########'),
        ]);

        foreach ($receipt->lines as $i => $line) {
            // The billed price, which may differ from what the goods were
            // received at — that difference is the variance this document
            // later corrects.
            $lineTotal = (int) $line->qty_base * $unitCost;

            SupplierBillLine::factory()
                ->forReceiptLine($line, $lineTotal)
                ->create([
                    'supplier_bill_id' => $bill->id,
                    'urutan' => $i + 1,
                    'unit_cost_rupiah' => $unitCost,
                ]);
        }

        app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);

        return $bill->refresh();
    }

    private function assertRefused(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected a DomainException.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }
}
