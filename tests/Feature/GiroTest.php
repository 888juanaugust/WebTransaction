<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Credit\CreditChecker;
use App\Domain\Giro\GiroRegister;
use App\Domain\Giro\GiroStatus;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Purchasing\GoodsReceiptPoster;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Reporting\ReceivablesAgeing;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Giro;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PaymentEntry;
use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bilyet giro — the postdated cheque the whole trade runs on.
 *
 * The thing these tests are really defending is a single distinction that is
 * easy to get wrong and expensive when you do: **a giro is not a payment.**
 *
 * Treat it as one and two things follow, both bad. The books claim money that
 * is not in the bank, so the neraca is wrong until the paper clears. And the
 * customer's credit limit frees up the moment they hand over a promise —
 * which is precisely how a customer who bounces giros keeps ordering, because
 * every bounce is followed by another giro that buys another month.
 *
 * So the ledger moves the balance into an account that says what backs it, and
 * the credit check goes on counting it. Those two figures disagreeing is not a
 * bug here; it is the feature, and most of what follows exists to hold it in
 * place.
 */
class GiroTest extends TestCase
{
    use RefreshDatabase;

    private const SKU = 'YH-GR-1';

    private Warehouse $gudang;

    private User $finance;

    private User $sales;

    private Company $pelanggan;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.secret_key', '');

        $this->gudang = Warehouse::factory()->create(['nama' => 'Gudang Pusat']);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->sales = User::factory()->sales()->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);

        $this->pelanggan = Company::factory()->creditLimit(100_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'payment_terms_days' => 30,
            'status' => Company::STATUS_ACTIVE,
        ]);

        Product::factory()->create(['kode' => self::SKU, 'qty_per_ctn' => 10, 'satuan_dasar' => 'PCS']);

        $version = PriceListVersion::factory()->published()->create([
            'effective_from' => now()->subDay()->toDateString(),
        ]);
        PriceListItem::factory()->create([
            'version_id' => $version->id, 'kode' => self::SKU, 'harga' => 100_000,
        ]);

        // Something on the shelf, so an order can actually be confirmed.
        $this->stockUp(1_000);
    }

    private function stockUp(int $qty): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces($qty, 60_000)->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);
    }

    // ------------------------------------------------------ taking one in

    public function test_a_giro_moves_the_debt_sideways_without_paying_anything(): void
    {
        /*
         * The whole design in one test. The customer has handed over paper for
         * the full invoice: Piutang Usaha empties into Piutang Giro, total
         * assets are unchanged, and the invoice is still open because nobody
         * has paid anything.
         */
        $invoice = $this->invoiceFor(100);
        $total = (int) $invoice->total_rupiah;

        $ledger = app(Ledger::class);
        $this->assertSame($total, $ledger->balanceOf(AccountCode::PIUTANG_USAHA));

        $this->receiveGiro($total, $invoice);

        $this->assertSame(0, $ledger->balanceOf(AccountCode::PIUTANG_USAHA));
        $this->assertSame($total, $ledger->balanceOf(AccountCode::PIUTANG_GIRO));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::BANK));

        $this->assertSame(Invoice::STATUS_OPEN, $invoice->refresh()->status);
        $this->assertSame($total, $invoice->amountOutstanding());
        $this->assertSame(0, PaymentEntry::query()->count());
    }

    public function test_a_giro_does_not_give_the_customer_their_credit_back(): void
    {
        /*
         * The single most valuable line in this feature. A giro is a promise
         * with a date on it that can fail, and treating it as settlement is
         * how a customer rolls one bounced cheque into another order.
         */
        $invoice = $this->invoiceFor(100);
        $total = (int) $invoice->total_rupiah;

        $credit = app(CreditChecker::class);
        $before = $credit->available($this->pelanggan);

        $this->receiveGiro($total, $invoice);

        $this->assertSame($before, $credit->available($this->pelanggan));
        $this->assertSame($total, app(OutstandingReceivables::class)->forCompany($this->pelanggan));
    }

    public function test_the_control_account_and_the_credit_check_disagree_on_purpose(): void
    {
        // Two figures, deliberately different, and both right. The books have
        // moved the balance; the customer still owes it.
        $invoice = $this->invoiceFor(100);
        $total = (int) $invoice->total_rupiah;

        $this->receiveGiro($total, $invoice);

        $receivables = app(OutstandingReceivables::class);

        $this->assertSame(0, $receivables->total());
        $this->assertSame($total, $receivables->forCompany($this->pelanggan));
        $this->assertSame($total, $receivables->giroHeld(null));
    }

    // -------------------------------------------------------- it clears

    public function test_clearing_settles_the_invoice_through_the_ordinary_payment_path(): void
    {
        /*
         * Clearing unwinds the instrument and then takes a normal payment,
         * rather than posting Dr Bank / Cr Piutang Giro directly. That costs
         * one extra journal entry and buys invoice settlement, the ageing
         * report and the reversal machinery — none of it reimplemented.
         */
        $invoice = $this->invoiceFor(100);
        $total = (int) $invoice->total_rupiah;

        $giro = $this->receiveGiro($total, $invoice);

        app(GiroRegister::class)->clear($giro, $this->finance);

        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PIUTANG_GIRO));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PIUTANG_USAHA));
        $this->assertSame($total, $ledger->balanceOf(AccountCode::BANK));

        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
        $this->assertSame(GiroStatus::Cair, $giro->refresh()->status);
        $this->assertNotNull($giro->payment_entry_id);
    }

    public function test_clearing_gives_the_credit_limit_back(): void
    {
        // The day it clears is the day it becomes money, and that is the day
        // the customer can order again.
        $invoice = $this->invoiceFor(100);
        $total = (int) $invoice->total_rupiah;

        $giro = $this->receiveGiro($total, $invoice);
        $credit = app(CreditChecker::class);

        $this->assertSame(100_000_000 - $total, $credit->available($this->pelanggan));

        app(GiroRegister::class)->clear($giro, $this->finance);

        $this->assertSame(100_000_000, $credit->available($this->pelanggan));
    }

    public function test_a_giro_covering_several_invoices_clears_into_the_unmatched_queue(): void
    {
        /*
         * One piece of paper against three months of invoices is the ordinary
         * case. Rather than inventing a giro-to-many-invoices allocation, it
         * clears unallocated and finance matches it on the screen that already
         * exists for exactly that.
         */
        $this->invoiceFor(50);
        $this->invoiceFor(50);

        $giro = $this->receiveGiro(20_000_000, null);

        app(GiroRegister::class)->clear($giro, $this->finance);

        $payment = PaymentEntry::query()->sole();

        $this->assertNull($payment->invoice_id);
        $this->assertSame(20_000_000, (int) $payment->amount_rupiah);
    }

    // ------------------------------------------------------- it bounces

    public function test_a_bounced_giro_puts_the_debt_straight_back(): void
    {
        /*
         * And there is nothing to reopen and no payment to reverse, because
         * neither ever happened. That is the payoff for not calling it a
         * payment in the first place.
         */
        $invoice = $this->invoiceFor(100);
        $total = (int) $invoice->total_rupiah;

        $giro = $this->receiveGiro($total, $invoice);

        app(GiroRegister::class)->bounce($giro, $this->finance, 'Saldo tidak cukup');

        $ledger = app(Ledger::class);
        $this->assertSame($total, $ledger->balanceOf(AccountCode::PIUTANG_USAHA));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PIUTANG_GIRO));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::BANK));

        $this->assertSame(Invoice::STATUS_OPEN, $invoice->refresh()->status);
        $this->assertSame(0, PaymentEntry::query()->count());
        $this->assertSame(GiroStatus::Ditolak, $giro->refresh()->status);
        $this->assertSame('Saldo tidak cukup', $giro->alasan_selesai);
    }

    public function test_a_bounce_must_say_what_the_bank_said(): void
    {
        // "Saldo tidak cukup" and "rekening ditutup" are different futures for
        // the relationship, and nobody remembers which it was six months on.
        $giro = $this->receiveGiro(5_000_000, null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('alasan dari bank');

        app(GiroRegister::class)->bounce($giro, $this->finance, '  ');
    }

    public function test_handing_a_giro_back_uncashed_is_not_a_failure(): void
    {
        // The customer settled in cash and took their paper home. Same
        // unwinding, no blame, and it must not look like a bounce in the
        // register — that is a credit judgement about a real person.
        $invoice = $this->invoiceFor(100);
        $giro = $this->receiveGiro((int) $invoice->total_rupiah, $invoice);

        app(GiroRegister::class)->cancel($giro, $this->finance, 'Pelanggan bayar tunai, giro dikembalikan');

        $this->assertSame(GiroStatus::Dibatalkan, $giro->refresh()->status);
        $this->assertSame(
            (int) $invoice->total_rupiah,
            app(Ledger::class)->balanceOf(AccountCode::PIUTANG_USAHA),
        );
    }

    public function test_a_settled_giro_cannot_be_settled_again(): void
    {
        $giro = $this->receiveGiro(5_000_000, null);

        app(GiroRegister::class)->clear($giro, $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah Cair');

        app(GiroRegister::class)->bounce($giro->refresh(), $this->finance, 'Terlambat');
    }

    // ---------------------------------------------------- giro we issue

    public function test_issuing_a_giro_commits_the_money_without_spending_it(): void
    {
        /*
         * The mirror. We still owe the supplier — the cash is in the account —
         * but it is committed to a date, and a payables figure that does not
         * separate the two makes next month's cash look better than it is.
         */
        $bill = $this->postedBill(6_000_000);
        $total = (int) $bill->total_rupiah;

        $giro = $this->issueGiro($total, $bill);

        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        $this->assertSame($total, $ledger->balanceOf(AccountCode::UTANG_GIRO));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::BANK));

        // Still owed, on the subledger that decides whether to pay again.
        $this->assertSame($total, app(SupplierLedger::class)->outstandingFor($this->pemasok));
        $this->assertSame(GiroStatus::Beredar, $giro->refresh()->status);
    }

    public function test_our_giro_clearing_pays_the_supplier(): void
    {
        $bill = $this->postedBill(6_000_000);
        $total = (int) $bill->total_rupiah;

        $giro = $this->issueGiro($total, $bill);

        app(GiroRegister::class)->clear($giro, $this->finance);

        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_GIRO));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        $this->assertSame(-$total, $ledger->balanceOf(AccountCode::BANK));

        $this->assertSame(SupplierBill::STATUS_PAID, $bill->refresh()->status);
        $this->assertSame(0, app(SupplierLedger::class)->outstandingFor($this->pemasok));
        $this->assertNotNull($giro->refresh()->supplier_payment_entry_id);
    }

    public function test_our_own_giro_can_bounce_too(): void
    {
        // Embarrassing, and it happens. The payable comes back exactly as it
        // was, and the supplier is owed money again rather than paid.
        $bill = $this->postedBill(6_000_000);
        $total = (int) $bill->total_rupiah;

        $giro = $this->issueGiro($total, $bill);

        app(GiroRegister::class)->bounce($giro, $this->finance, 'Saldo rekening kita tidak cukup');

        $ledger = app(Ledger::class);
        $this->assertSame($total, $ledger->balanceOf(AccountCode::UTANG_USAHA));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::UTANG_GIRO));
        $this->assertSame(SupplierBill::STATUS_OPEN, $bill->refresh()->status);
    }

    // -------------------------------------------- the control accounts

    public function test_the_books_agree_with_the_subledgers_at_every_stage(): void
    {
        /*
         * The check that proves the posting rules went to the right accounts.
         * Run at each stage rather than only at the end, because a rule that
         * is wrong in both directions nets out and passes a final check.
         */
        $reconciliation = app(LedgerReconciliation::class);

        $invoice = $this->invoiceFor(100);
        $bill = $this->postedBill(6_000_000);
        $this->assertTrue($reconciliation->isClean());

        $masuk = $this->receiveGiro((int) $invoice->total_rupiah, $invoice);
        $keluar = $this->issueGiro((int) $bill->total_rupiah, $bill);
        $this->assertSame([], $reconciliation->discrepancies());

        app(GiroRegister::class)->clear($masuk, $this->finance);
        $this->assertSame([], $reconciliation->discrepancies());

        app(GiroRegister::class)->bounce($keluar, $this->finance, 'Dana kurang');
        $this->assertTrue($reconciliation->isClean());
    }

    // ----------------------------------------------- what it refuses

    public function test_the_same_piece_of_paper_cannot_be_entered_twice(): void
    {
        // Entering one twice would double an asset that does not exist.
        $this->receiveGiro(5_000_000, null, warkat: 'AB123456');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah pernah dicatat');

        $this->receiveGiro(5_000_000, null, warkat: 'AB123456');
    }

    public function test_the_bank_name_is_folded_so_the_check_cannot_be_dodged(): void
    {
        /*
         * "BCA", "bca" and " Bca " are one bank. Without folding them together
         * the uniqueness check is decoration: the same warkat goes in three
         * times and the asset triples.
         */
        $this->receiveGiro(5_000_000, null, warkat: 'AB123456', bank: 'BCA');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah pernah dicatat');

        $this->receiveGiro(5_000_000, null, warkat: 'AB123456', bank: '  bca ');
    }

    public function test_the_same_number_from_a_different_bank_is_fine(): void
    {
        // Warkat numbers are printed per bank and six digits long, so two
        // banks colliding over the years is ordinary rather than remarkable.
        $this->receiveGiro(5_000_000, null, warkat: 'AB123456', bank: 'BCA');
        $second = $this->receiveGiro(5_000_000, null, warkat: 'AB123456', bank: 'Mandiri');

        $this->assertSame('MANDIRI', $second->bank_penerbit);
        $this->assertSame(2, Giro::query()->count());
    }

    public function test_a_giro_dated_before_it_was_handed_over_is_a_typo(): void
    {
        // Almost always a year typed wrong, which would drop the whole thing
        // into the overdue queue on day one and leave it there.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak boleh sebelum');

        app(GiroRegister::class)->receive(
            company: $this->pelanggan,
            nilaiRupiah: 5_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'XX999999',
            jatuhTempo: now()->subDay(),
            actor: $this->finance,
        );
    }

    public function test_a_giro_worth_nothing_is_not_a_giro(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('lebih dari nol');

        $this->receiveGiro(0, null);
    }

    public function test_an_invoice_belonging_to_somebody_else_is_refused(): void
    {
        $lain = Company::factory()->creditLimit(10_000_000)->create(['status' => Company::STATUS_ACTIVE]);
        $invoice = Invoice::factory()->create(['company_id' => $lain->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('milik pelanggan lain');

        app(GiroRegister::class)->receive(
            company: $this->pelanggan,
            nilaiRupiah: 1_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'ZZ111111',
            jatuhTempo: now()->addDays(30),
            actor: $this->finance,
            invoice: $invoice,
        );
    }

    // --------------------------------------------------- banking it

    public function test_banking_a_giro_is_recorded_but_moves_no_money(): void
    {
        /*
         * "Banked and waiting to hear" and "still in the drawer" are both
         * work, and different work: one is chasing the bank and the other is
         * chasing yourself. Neither is an accounting event.
         */
        // Handed over two months ago, dated for yesterday: bankable today.
        $giro = $this->receiveGiro(
            5_000_000, null,
            jatuhTempo: now()->subDay(),
            diterima: now()->subDays(60),
        );

        $before = JournalEntry::query()->count();

        app(GiroRegister::class)->markDeposited($giro, $this->finance);

        $this->assertNotNull($giro->refresh()->tanggal_setor);
        $this->assertSame(GiroStatus::Beredar, $giro->status);
        $this->assertSame($before, JournalEntry::query()->count());
    }

    public function test_a_giro_cannot_be_banked_before_its_date(): void
    {
        $giro = $this->receiveGiro(5_000_000, null, jatuhTempo: now()->addDays(30));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('baru bisa disetor');

        app(GiroRegister::class)->markDeposited($giro, $this->finance);
    }

    public function test_we_do_not_bank_a_giro_we_issued(): void
    {
        // The supplier banks it. We find out when the money leaves.
        $giro = $this->issueGiro(5_000_000, null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('disetorkan oleh pemasok');

        app(GiroRegister::class)->markDeposited($giro, $this->finance);
    }

    // ------------------------------------------------------- access

    public function test_sales_may_not_touch_a_giro(): void
    {
        /*
         * They are usually the ones handed it at the counter, and they still
         * do not decide its fate: recording one changes the books and
         * recording a bounce changes them back, which is moving what a
         * customer owes by another name.
         */
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak berhak');

        app(GiroRegister::class)->receive(
            company: $this->pelanggan,
            nilaiRupiah: 5_000_000,
            bankPenerbit: 'BCA',
            nomorWarkat: 'SS111111',
            jatuhTempo: now()->addDays(30),
            actor: $this->sales,
        );
    }

    public function test_warehouse_may_not_clear_a_giro(): void
    {
        $giro = $this->receiveGiro(5_000_000, null);
        $warehouse = User::factory()->role(Role::Warehouse)->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak berhak');

        app(GiroRegister::class)->clear($giro, $warehouse);
    }

    public function test_every_step_is_written_to_the_audit_log(): void
    {
        $giro = $this->receiveGiro(5_000_000, null);

        app(GiroRegister::class)->bounce($giro, $this->finance, 'Saldo tidak cukup');

        $this->assertDatabaseHas('audit_logs', ['action' => 'giro_received']);

        $tolak = AuditLog::query()->where('action', 'giro_ditolak')->firstOrFail();

        $this->assertSame($this->finance->id, $tolak->actor_id);
        $this->assertSame('Saldo tidak cukup', $tolak->alasan);
    }

    // ------------------------------------------------------ the report

    public function test_the_ageing_shows_giro_separately_and_still_ties(): void
    {
        /*
         * A customer who owes 44 million of which 20 is covered by paper due
         * next Tuesday is a different conversation from one who owes 44
         * million and has sent nothing. The column exists to make those two
         * rows look different — and the report has to keep agreeing with the
         * balance sheet while it does.
         */
        $invoice = $this->invoiceFor(100);
        $this->receiveGiro(4_000_000, $invoice);

        $report = app(ReceivablesAgeing::class)->build();

        $this->assertSame(-4_000_000, $report->rows[0]['dijamin_giro']);
        // No PERIKSA note: the report still equals Piutang Usaha.
        $this->assertSame([], $report->catatan);

        $visible = array_map(fn ($c) => $c->key, $report->visibleColumns());
        $this->assertContains('dijamin_giro', $visible);
    }

    public function test_the_giro_column_stays_out_of_the_way_when_nobody_uses_giro(): void
    {
        // A business that settles by transfer would otherwise carry a column
        // of zeroes to the right edge forever.
        $this->invoiceFor(100);

        $report = app(ReceivablesAgeing::class)->build();

        $visible = array_map(fn ($c) => $c->key, $report->visibleColumns());
        $this->assertNotContains('dijamin_giro', $visible);
    }

    // --- helpers ------------------------------------------------------------

    /** An order driven to awaiting payment, so a real invoice is on the books. */
    private function invoiceFor(int $qty): Invoice
    {
        $order = Order::factory()->create([
            'company_id' => $this->pelanggan->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->sales->id,
        ]);
        OrderLine::factory()->qty($qty)->create([
            'order_id' => $order->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $machine->submit($order->refresh(), $this->sales);
        $machine->confirm($order->refresh(), $this->approver());
        $machine->awaitPayment($order->refresh(), $this->sales);

        return $order->refresh()->invoice;
    }

    private function receiveGiro(
        int $nilai,
        ?Invoice $invoice,
        string $warkat = 'AB000001',
        string $bank = 'BCA',
        ?\DateTimeInterface $jatuhTempo = null,
        ?\DateTimeInterface $diterima = null,
    ): Giro {
        static $seq = 0;

        return app(GiroRegister::class)->receive(
            company: $this->pelanggan,
            nilaiRupiah: $nilai,
            bankPenerbit: $bank,
            nomorWarkat: $warkat === 'AB000001' ? 'AB'.str_pad((string) ++$seq, 6, '0', STR_PAD_LEFT) : $warkat,
            jatuhTempo: $jatuhTempo ?? now()->addDays(60),
            actor: $this->finance,
            invoice: $invoice,
            diterima: $diterima,
        );
    }

    private function issueGiro(int $nilai, ?SupplierBill $bill): Giro
    {
        static $seq = 0;

        return app(GiroRegister::class)->issue(
            supplier: $this->pemasok,
            nilaiRupiah: $nilai,
            bankPenerbit: 'BCA',
            nomorWarkat: 'KL'.str_pad((string) ++$seq, 6, '0', STR_PAD_LEFT),
            jatuhTempo: now()->addDays(30),
            actor: $this->finance,
            bill: $bill,
        );
    }

    /** A posted supplier bill with goods behind it, so the books stay tied. */
    private function postedBill(int $nilai): SupplierBill
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'warehouse_id' => $this->gudang->id,
            'created_by' => $this->finance->id,
        ]);
        GoodsReceiptLine::factory()->pieces(100, intdiv($nilai, 100))->create([
            'goods_receipt_id' => $receipt->id, 'sku' => self::SKU, 'urutan' => 1,
        ]);
        app(GoodsReceiptPoster::class)->post($receipt->refresh(), $this->finance);

        $bill = SupplierBill::factory()->create([
            'supplier_id' => $this->pemasok->id,
            'nomor_faktur_pajak' => '010.000-26.'.fake()->unique()->numerify('########'),
        ]);
        SupplierBillLine::factory()
            ->forReceiptLine($receipt->lines()->first())
            ->create(['supplier_bill_id' => $bill->id, 'urutan' => 1]);

        return app(SupplierBillPoster::class)->post($bill->refresh(), $this->finance);
    }
}
