<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Banking\BankReconciler;
use App\Domain\Banking\StatementDirection;
use App\Domain\Payments\PaymentLedger;
use App\Domain\Purchasing\SupplierLedger;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Proving the Bank account against the bank.
 *
 * Every other control account in this system is checked against a subledger we
 * also wrote. Those checks prove the posting rules agree with each other, and
 * not one of them can prove the money is actually in the account — both sides
 * are our own arithmetic.
 *
 * This is the one that can, because the statement balance comes from outside.
 * So most of what follows is about the two adjustments, which are easy to sign
 * backwards: a deposit the bank has not seen makes our book **higher**, and a
 * cheque it has not paid makes our book **lower**. Get either the wrong way
 * round and the difference comes out at exactly twice the amount, which looks
 * like a missing transaction and sends somebody hunting for a payment that was
 * never there.
 */
class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $pelanggan;

    private Supplier $pemasok;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Far enough forward that the August and September statements these
         * tests reconcile have actually happened. `open()` refuses a statement
         * date in the future, which is correct — a statement that has not been
         * issued cannot be reconciled — and it makes the clock part of the
         * fixture.
         */
        $this->travelTo('2026-10-05 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pemasok = Supplier::factory()->create(['nama' => 'PT Pemasok Barang']);
        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    // ------------------------------------------------------- the arithmetic

    public function test_a_quiet_month_where_everything_cleared(): void
    {
        /*
         * The base case. Two receipts, both on the statement, nothing
         * outstanding: the bank should read exactly what the books read.
         */
        $this->receive(10_000_000, '2026-08-05');
        $this->receive(4_000_000, '2026-08-12');

        $rec = $this->open('2026-08-31', 14_000_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        $summary = app(BankReconciler::class)->summarise($rec);

        $this->assertSame(14_000_000, $summary->saldoBuku);
        $this->assertSame(0, $summary->setoranBeredar);
        $this->assertSame(0, $summary->penarikanBeredar);
        $this->assertSame(0, $summary->selisih);
        $this->assertTrue($summary->isReconciled());
    }

    public function test_a_deposit_the_bank_has_not_seen_comes_off_the_book_balance(): void
    {
        /*
         * We banked four million on the 31st and it credited on 1 September.
         * Our book says 14; the statement says 10. The reconciliation has to
         * explain the four rather than call it a difference.
         */
        $this->receive(10_000_000, '2026-08-05');
        $late = $this->receive(4_000_000, '2026-08-31');

        $rec = $this->open('2026-08-31', 10_000_000);
        $this->tickAllExcept($rec, $late);

        $summary = app(BankReconciler::class)->summarise($rec);

        $this->assertSame(14_000_000, $summary->saldoBuku);
        $this->assertSame(4_000_000, $summary->setoranBeredar);
        $this->assertSame(10_000_000, $summary->saldoDiharapkan);
        $this->assertSame(0, $summary->selisih);
    }

    public function test_a_cheque_the_bank_has_not_paid_goes_back_on(): void
    {
        /*
         * The opposite sign, and the one that is easy to get wrong. We paid a
         * supplier six million on the 29th; they banked it in September. Our
         * book says 4; the bank still says 10.
         */
        $this->receive(10_000_000, '2026-08-05');
        $paid = $this->pay(6_000_000, '2026-08-29');

        $rec = $this->open('2026-08-31', 10_000_000);
        $this->tickAllExcept($rec, $paid);

        $summary = app(BankReconciler::class)->summarise($rec);

        $this->assertSame(4_000_000, $summary->saldoBuku);
        $this->assertSame(6_000_000, $summary->penarikanBeredar);
        $this->assertSame(10_000_000, $summary->saldoDiharapkan);
        $this->assertSame(0, $summary->selisih);
    }

    public function test_both_adjustments_at_once_pull_in_opposite_directions(): void
    {
        // The sign error this test exists to catch nets out to zero if both
        // are wrong the same way, so both are present and different sizes.
        $this->receive(10_000_000, '2026-08-05');
        $late = $this->receive(4_000_000, '2026-08-31');
        $paid = $this->pay(6_000_000, '2026-08-29');

        $rec = $this->open('2026-08-31', 12_000_000);
        $this->tickAllExcept($rec, $late, $paid);

        $summary = app(BankReconciler::class)->summarise($rec);

        // Book 8 − 4 deposit in transit + 6 outstanding cheque = 10... which
        // is not 12, so this one does *not* reconcile. That is the point of
        // the next assertion.
        $this->assertSame(8_000_000, $summary->saldoBuku);
        $this->assertSame(10_000_000, $summary->saldoDiharapkan);
        $this->assertSame(-2_000_000, $summary->selisih);
        $this->assertFalse($summary->isReconciled());
    }

    public function test_the_hint_points_the_right_way(): void
    {
        /*
         * The sign alone tells somebody nothing, and the two cases send them
         * looking in different places. Which is why the *causes* are asserted
         * and not merely that some sentence comes back: an unrecorded bank
         * charge leaves the statement lower than the books, and naming it under
         * the other sign sends somebody hunting a duplicate receipt that does
         * not exist.
         */
        $this->receive(10_000_000, '2026-08-05');

        $reconciler = app(BankReconciler::class);

        // Statement lower than the books: something took money out of the
        // account that the books have never heard of.
        $tooHigh = $this->open('2026-08-31', 9_000_000);
        $reconciler->tickAll($tooHigh, $this->finance);

        $hint = $reconciler->summarise($tooHigh)->hint();
        $this->assertStringContainsString('biaya bank', $hint);
        $this->assertStringContainsString('tercatat dua kali', $hint);
        $this->assertStringNotContainsString('bunga bank', $hint);

        // And the other way: the bank holds more than the books expect.
        $reconciler->finalise(
            tap($tooHigh, fn () => $reconciler->recordStatementItem(
                $tooHigh, 'Biaya administrasi', 1_000_000,
                StatementDirection::Keluar, AccountCode::BEBAN_OPERASIONAL, $this->finance,
            )),
            $this->finance,
        );

        $this->receive(5_000_000, '2026-09-10');
        $tooLow = $this->open('2026-09-30', 20_000_000);
        $reconciler->tickAll($tooLow, $this->finance);

        $hint = $reconciler->summarise($tooLow)->hint();
        $this->assertStringContainsString('bunga bank', $hint);
        $this->assertStringNotContainsString('biaya bank', $hint);
    }

    // ---------------------------------------------- what the books missed

    public function test_a_bank_charge_nobody_recorded_is_posted_and_ticks_itself(): void
    {
        /*
         * The half of reconciliation that changes the books. Without it the
         * exercise finds a difference of fifteen thousand and leaves somebody
         * to post a journal by hand — which is how a reconciliation gets
         * abandoned three months in.
         */
        $this->receive(10_000_000, '2026-08-05');

        $rec = $this->open('2026-08-31', 9_985_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        $this->assertSame(15_000, app(BankReconciler::class)->summarise($rec)->selisih);

        app(BankReconciler::class)->recordStatementItem(
            $rec,
            'Biaya administrasi bank Agustus',
            15_000,
            StatementDirection::Keluar,
            AccountCode::BEBAN_OPERASIONAL,
            $this->finance,
        );

        $summary = app(BankReconciler::class)->summarise($rec->refresh());

        $this->assertSame(9_985_000, $summary->saldoBuku);
        $this->assertSame(0, $summary->belumDicentang);
        $this->assertSame(0, $summary->selisih);

        $ledger = app(Ledger::class);
        $this->assertSame(9_985_000, $ledger->balanceOf(AccountCode::BANK));
        $this->assertSame(15_000, $ledger->balanceOf(AccountCode::BEBAN_OPERASIONAL));
    }

    public function test_interest_received_lands_outside_penjualan(): void
    {
        /*
         * Bank interest folded into Penjualan would flatter the gross margin
         * on goods that were never sold, and Penjualan is the line every
         * margin figure in the system divides into.
         */
        $this->receive(10_000_000, '2026-08-05');

        $rec = $this->open('2026-08-31', 10_042_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        app(BankReconciler::class)->recordStatementItem(
            $rec,
            'Bunga bank',
            42_000,
            StatementDirection::Masuk,
            AccountCode::PENDAPATAN_LAIN,
            $this->finance,
        );

        $ledger = app(Ledger::class);

        $this->assertSame(42_000, $ledger->balanceOf(AccountCode::PENDAPATAN_LAIN));
        $this->assertSame(0, $ledger->balanceOf(AccountCode::PENJUALAN));
        $this->assertSame(0, app(BankReconciler::class)->summarise($rec->refresh())->selisih);
    }

    public function test_several_items_in_one_month_each_get_their_own_entry(): void
    {
        /*
         * The source is the item, not the reconciliation. `Ledger::post()` is
         * idempotent on (source, jenis), so one source would collapse four
         * charges into one entry and silently drop three of them.
         */
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 9_970_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        foreach ([10_000, 12_000, 8_000] as $i => $amount) {
            app(BankReconciler::class)->recordStatementItem(
                $rec,
                'Biaya transfer '.($i + 1),
                $amount,
                StatementDirection::Keluar,
                AccountCode::BEBAN_OPERASIONAL,
                $this->finance,
            );
        }

        $this->assertSame(3, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_REKONSILIASI_BANK)
            ->count());

        $this->assertSame(0, app(BankReconciler::class)->summarise($rec->refresh())->selisih);
    }

    public function test_an_item_carries_the_entry_it_posted(): void
    {
        /*
         * The link both ways. An item without a journal entry would be a
         * claim about the bank that never reached the books — and the entry
         * without the item would be a charge nobody could explain.
         */
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 9_985_000);

        $item = app(BankReconciler::class)->recordStatementItem(
            $rec, 'Biaya administrasi', 15_000, StatementDirection::Keluar,
            AccountCode::BEBAN_OPERASIONAL, $this->finance,
        );

        $this->assertNotNull($item->journal_entry_id);
        $this->assertSame(
            JournalEntry::JENIS_REKONSILIASI_BANK,
            $item->journalEntry->jenis,
        );
        $this->assertStringContainsString($rec->nomor, $item->journalEntry->keterangan);
    }

    public function test_an_item_worth_nothing_is_not_an_item(): void
    {
        // A zero-rupiah statement line explains nothing and would post an
        // entry saying something happened when nothing did.
        $rec = $this->open('2026-08-31', 0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('lebih dari nol');

        app(BankReconciler::class)->recordStatementItem(
            $rec, 'Kosong', 0, StatementDirection::Keluar,
            AccountCode::BEBAN_OPERASIONAL, $this->finance,
        );
    }

    public function test_an_item_cannot_be_posted_against_bank_itself(): void
    {
        // It would cancel itself out and explain nothing.
        $rec = $this->open('2026-08-31', 0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak boleh rekening ini sendiri');

        app(BankReconciler::class)->recordStatementItem(
            $rec, 'Salah akun', 1_000, StatementDirection::Masuk,
            AccountCode::BANK, $this->finance,
        );
    }

    public function test_an_item_cannot_be_dated_after_the_statement(): void
    {
        $rec = $this->open('2026-08-31', 0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('setelah tanggal rekening koran');

        app(BankReconciler::class)->recordStatementItem(
            $rec, 'Biaya September', 1_000, StatementDirection::Keluar,
            AccountCode::BEBAN_OPERASIONAL, $this->finance,
            tanggal: Carbon::parse('2026-09-02'),
        );
    }

    // ------------------------------------------------------- finalising

    public function test_finalising_is_refused_while_anything_is_unexplained(): void
    {
        /*
         * The strictness is the feature. A reconciliation that can be signed
         * off with "Rp 43.500 unexplained" is one nobody chases, and three
         * months later the figure is Rp 900.000 and nobody knows when it
         * started.
         */
        $this->receive(10_000_000, '2026-08-05');

        $rec = $this->open('2026-08-31', 9_950_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('selisih 50.000');

        app(BankReconciler::class)->finalise($rec, $this->finance);
    }

    public function test_finalising_freezes_the_statement_it_signed_off(): void
    {
        /*
         * A reconciliation is a claim about a moment. Recomputing it later
         * against a ledger that has moved on — which a back-dated entry would
         * do — would quietly rewrite what somebody signed.
         */
        $this->receive(10_000_000, '2026-08-05');
        $late = $this->receive(4_000_000, '2026-08-31');

        $rec = $this->open('2026-08-31', 10_000_000);
        $this->tickAllExcept($rec, $late);

        $done = app(BankReconciler::class)->finalise($rec, $this->finance);

        $this->assertTrue($done->isFinalised());
        $this->assertSame(14_000_000, (int) $done->saldo_buku_rupiah);
        $this->assertSame(4_000_000, (int) $done->setoran_beredar_rupiah);
        $this->assertSame(0, (int) $done->selisih_rupiah);
        $this->assertSame($this->finance->id, $done->finalised_by);

        // Something posted afterwards must not disturb what was signed off.
        $this->receive(7_000_000, '2026-08-20');

        $this->assertSame(14_000_000, (int) $done->refresh()->saldo_buku_rupiah);
    }

    public function test_a_finalised_reconciliation_cannot_be_touched(): void
    {
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);
        app(BankReconciler::class)->finalise($rec, $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah selesai');

        app(BankReconciler::class)->tickAll($rec->refresh(), $this->finance);
    }

    // --------------------------------------------- carrying things forward

    public function test_an_outstanding_cheque_turns_up_on_next_months_statement(): void
    {
        /*
         * The property that makes the whole design work: an unticked line is
         * one with no row in the ticks table, so it simply appears again next
         * month. Nothing tracks it, nothing carries it, and nothing can forget
         * it.
         */
        $this->receive(10_000_000, '2026-08-05');
        $paid = $this->pay(6_000_000, '2026-08-29');

        $august = $this->open('2026-08-31', 10_000_000);
        $this->tickAllExcept($august, $paid);
        app(BankReconciler::class)->finalise($august, $this->finance);

        $september = $this->open('2026-09-30', 4_000_000);

        $candidates = app(BankReconciler::class)->candidateLines($september);

        $this->assertCount(1, $candidates);
        $this->assertSame(6_000_000, (int) $candidates->first()->kredit_rupiah);

        app(BankReconciler::class)->tickAll($september, $this->finance);

        $this->assertSame(0, app(BankReconciler::class)->summarise($september)->selisih);
    }

    public function test_a_line_ticked_last_month_never_comes_back(): void
    {
        // Otherwise September would present August's whole history as
        // outstanding, and the difference would be a month of trading.
        $this->receive(10_000_000, '2026-08-05');

        $august = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($august, $this->finance);
        app(BankReconciler::class)->finalise($august, $this->finance);

        $september = $this->open('2026-09-30', 10_000_000);

        $this->assertCount(0, app(BankReconciler::class)->candidateLines($september));
        $this->assertSame(0, app(BankReconciler::class)->summarise($september)->selisih);
    }

    public function test_a_line_after_the_statement_date_is_not_a_candidate(): void
    {
        // It cannot be on a statement that closed before it happened.
        $this->receive(10_000_000, '2026-08-05');
        $this->receive(3_000_000, '2026-09-02');

        $rec = $this->open('2026-08-31', 10_000_000);

        $this->assertCount(1, app(BankReconciler::class)->candidateLines($rec));
    }

    public function test_the_book_balance_is_read_at_the_statement_date_not_today(): void
    {
        /*
         * September's trading must not appear in August's book balance. The
         * whole reconciliation is a claim about one closing date, and a book
         * figure taken as of *now* would drift every day the statement sat
         * unreconciled — reconciling nothing on the day it was opened and
         * failing a week later for no reason anybody could see.
         */
        $this->receive(10_000_000, '2026-08-05');
        $this->receive(3_000_000, '2026-09-02');

        $rec = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        $summary = app(BankReconciler::class)->summarise($rec);

        $this->assertSame(10_000_000, $summary->saldoBuku);
        $this->assertSame(0, $summary->selisih);
        // The ledger really does hold more than that today.
        $this->assertSame(13_000_000, app(Ledger::class)->balanceOf(AccountCode::BANK));
    }

    // ------------------------------------------------------- what it refuses

    public function test_two_reconciliations_cannot_claim_the_same_statement(): void
    {
        $this->open('2026-08-31', 0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah ada');

        $this->open('2026-08-31', 0);
    }

    public function test_a_month_already_signed_off_cannot_be_reopened_by_the_back_door(): void
    {
        /*
         * Opening a January reconciliation in September would present three
         * seasons of already-explained history as though it were outstanding.
         */
        $this->receive(10_000_000, '2026-08-05');
        $august = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($august, $this->finance);
        app(BankReconciler::class)->finalise($august, $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah direkonsiliasi sampai');

        $this->open('2026-07-31', 0);
    }

    public function test_there_is_no_statement_for_a_date_that_has_not_happened(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('belum lewat');

        $this->open(Carbon::now()->addDays(3)->toDateString(), 0);
    }

    public function test_a_line_ticked_elsewhere_is_refused_with_a_sentence(): void
    {
        // The unique index would refuse it too; this refuses it readably.
        $this->receive(10_000_000, '2026-08-05');

        $august = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($august, $this->finance);
        app(BankReconciler::class)->finalise($august, $this->finance);

        $line = BankReconciliationLine::query()->sole()->journalLine;
        $september = $this->open('2026-09-30', 10_000_000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('sudah dicentang di rekonsiliasi lain');

        app(BankReconciler::class)->tick($september, $line, $this->finance);
    }

    public function test_only_bank_lines_can_be_ticked(): void
    {
        $this->receive(10_000_000, '2026-08-05');

        $piutang = Account::byCode(AccountCode::PIUTANG_USAHA);
        $line = JournalEntry::query()->first()->lines()->where('account_id', $piutang->id)->firstOrFail();

        $rec = $this->open('2026-08-31', 0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('bukan milik rekening');

        app(BankReconciler::class)->tick($rec, $line, $this->finance);
    }

    public function test_unticking_only_ever_touches_this_reconciliation(): void
    {
        /*
         * Unticking a line a finalised reconciliation claimed would silently
         * reopen a month that has already been signed off.
         */
        $this->receive(10_000_000, '2026-08-05');
        $august = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($august, $this->finance);
        app(BankReconciler::class)->finalise($august, $this->finance);

        $line = BankReconciliationLine::query()->sole()->journalLine;
        $september = $this->open('2026-09-30', 10_000_000);

        app(BankReconciler::class)->untick($september, $line, $this->finance);

        $this->assertSame(1, BankReconciliationLine::query()->count());
    }

    // -------------------------------------------------------------- access

    public function test_sales_may_not_reconcile_the_bank(): void
    {
        $sales = User::factory()->sales()->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak berhak');

        app(BankReconciler::class)->open(Carbon::parse('2026-08-31'), 0, $sales);
    }

    public function test_warehouse_may_not_finalise_one(): void
    {
        $rec = $this->open('2026-08-31', 0);
        $warehouse = User::factory()->role(Role::Warehouse)->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('tidak berhak');

        app(BankReconciler::class)->finalise($rec, $warehouse);
    }

    public function test_opening_and_signing_off_are_both_in_the_audit_log(): void
    {
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);
        app(BankReconciler::class)->finalise($rec, $this->finance);

        $this->assertDatabaseHas('audit_logs', ['action' => 'bank_reconciliation_opened']);

        $done = AuditLog::query()->where('action', 'bank_reconciliation_finalised')->firstOrFail();

        $this->assertSame($this->finance->id, $done->actor_id);
        $this->assertSame(10_000_000, $done->new_value['saldo_rekening_rupiah']);
    }

    // ----------------------------------------------------- how stale it is

    public function test_a_bank_nobody_has_ever_reconciled_says_so(): void
    {
        // Null, not a large number. "Never" is a different and worse answer,
        // and it is the one a new installation gives.
        $this->assertNull(app(BankReconciler::class)->daysSinceLastReconciled());
    }

    public function test_how_long_since_the_last_signed_off_statement(): void
    {
        $this->receive(10_000_000, '2026-08-05');

        // Twenty days after the statement it signs off.
        $this->travelTo('2026-09-20 09:00:00');

        $rec = $this->open('2026-08-31', 10_000_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);
        app(BankReconciler::class)->finalise($rec, $this->finance);

        $this->assertSame(20, app(BankReconciler::class)->daysSinceLastReconciled());
    }

    public function test_a_draft_does_not_count_as_reconciled(): void
    {
        // Somebody opening a reconciliation and walking away is exactly the
        // case the staleness figure exists to catch.
        $this->open('2026-08-31', 0);

        $this->assertNull(app(BankReconciler::class)->daysSinceLastReconciled());
    }

    // --- helpers ------------------------------------------------------------

    private function open(string $date, int $balance): BankReconciliation
    {
        return app(BankReconciler::class)
            ->open(Carbon::parse($date), $balance, $this->finance);
    }

    /** Money in: Dr Bank / Cr Piutang Usaha, through the real payment ledger. */
    private function receive(int $amount, string $date): JournalEntry
    {
        $entry = app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: $amount,
            actor: $this->finance,
            catatan: 'Transfer masuk',
            paidAt: Carbon::parse($date),
        );

        return JournalEntry::query()
            ->where('source_type', $entry::class)
            ->where('source_id', (string) $entry->id)
            ->firstOrFail();
    }

    /** Money out: Dr Utang Usaha / Cr Bank. */
    private function pay(int $amount, string $date): JournalEntry
    {
        $bill = SupplierBill::factory()->create(['supplier_id' => $this->pemasok->id]);

        $entry = app(SupplierLedger::class)->recordPayment(
            supplier: $this->pemasok,
            amountRupiah: $amount,
            actor: $this->finance,
            bill: $bill,
            paidAt: Carbon::parse($date),
        );

        return JournalEntry::query()
            ->where('source_type', $entry::class)
            ->where('source_id', (string) $entry->id)
            ->firstOrFail();
    }

    /** Tick everything except the Bank lines of the given entries. */
    private function tickAllExcept(BankReconciliation $rec, JournalEntry ...$leaveOut): void
    {
        $bank = Account::byCode(AccountCode::BANK);

        $skip = collect($leaveOut)
            ->map(fn (JournalEntry $e) => $e->lines()->where('account_id', $bank->id)->first()?->id)
            ->filter()
            ->all();

        $reconciler = app(BankReconciler::class);

        foreach ($reconciler->candidateLines($rec) as $line) {
            if (in_array($line->id, $skip, true)) {
                continue;
            }

            $reconciler->tick($rec, $line, $this->finance);
        }
    }
}
