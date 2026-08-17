<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\BalanceSheet;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Accounting\PeriodCloser;
use App\Domain\Accounting\ProfitAndLoss;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Closing December closes the year.
 *
 * One entry zeroes every income and expense account into Laba Ditahan, so
 * January starts from nil and the balance sheet stops having to compute a
 * result nobody has posted. The property to hold on to throughout is that the
 * neraca balances either side of it and the *totals* do not move — a closing
 * entry rearranges equity, it does not create any.
 */
class YearEndCloseTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    private PeriodCloser $closer;

    private User $finance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2027-06-15 09:00:00');

        $this->ledger = app(Ledger::class);
        $this->closer = app(PeriodCloser::class);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the entry

    public function test_closing_december_zeroes_income_and_expense(): void
    {
        $this->tradingYear(2026);

        $this->assertSame(20_000_000, $this->balance(AccountCode::PENJUALAN, '2026-12-31'));

        $this->closeYear(2026);

        $this->assertSame(0, $this->balance(AccountCode::PENJUALAN, '2026-12-31'));
        $this->assertSame(0, $this->balance(AccountCode::HARGA_POKOK_PENJUALAN, '2026-12-31'));
        $this->assertSame(0, $this->balance(AccountCode::BEBAN_OPERASIONAL, '2026-12-31'));
    }

    public function test_the_result_lands_in_retained_earnings(): void
    {
        // 20,000,000 sold, 12,000,000 cost, 3,000,000 overhead.
        $this->tradingYear(2026);
        $this->closeYear(2026);

        $this->assertSame(5_000_000, $this->balance(AccountCode::LABA_DITAHAN, '2026-12-31'));
    }

    public function test_a_loss_reduces_retained_earnings(): void
    {
        $this->tradingYear(2026, sale: 10_000_000, cost: 12_000_000, overhead: 1_000_000);
        $this->closeYear(2026);

        $this->assertSame(-3_000_000, $this->balance(AccountCode::LABA_DITAHAN, '2026-12-31'));
        $this->assertTrue($this->ledger->isBalanced());
    }

    public function test_the_entry_names_every_account_it_closed_rather_than_just_the_profit(): void
    {
        /*
         * A reader of this entry should see the year's sales and the year's
         * cost of sales on it. "Profit, 5,000,000" against Laba Ditahan
         * balances and explains nothing.
         */
        $this->tradingYear(2026);
        $this->closeYear(2026);

        $entry = JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->sole();
        $codes = $entry->lines->map(fn ($l) => $l->account->kode)->all();

        $this->assertContains(AccountCode::PENJUALAN, $codes);
        $this->assertContains(AccountCode::HARGA_POKOK_PENJUALAN, $codes);
        $this->assertContains(AccountCode::BEBAN_OPERASIONAL, $codes);
        $this->assertContains(AccountCode::LABA_DITAHAN, $codes);
        $this->assertTrue($entry->isBalanced());
    }

    public function test_the_entry_is_dated_the_last_day_of_the_year_it_closes(): void
    {
        // Not the day somebody happened to run it. A close performed in March
        // still belongs to the December it closes.
        $this->tradingYear(2026);
        $this->closeYear(2026);

        $entry = JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->sole();

        $this->assertSame('2026-12-31', $entry->tanggal->toDateString());
        $this->assertSame('2027-06-15', $entry->posted_at->toDateString());
    }

    public function test_the_period_row_points_at_the_entry_it_produced(): void
    {
        $this->tradingYear(2026);
        $this->closeYear(2026);

        $december = AccountingPeriod::query()->where('tahun', 2026)->where('bulan', 12)->sole();
        $entry = JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->sole();

        $this->assertSame($entry->id, $december->closing_entry_id);
        $this->assertTrue($december->isYearEnd());
    }

    public function test_closing_a_month_that_is_not_december_posts_nothing(): void
    {
        $this->tradingYear(2026);

        $this->closer->close(2026, 1, $this->finance);

        $this->assertSame(0, JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->count());
        $this->assertNull(AccountingPeriod::query()->where('bulan', 1)->sole()->closing_entry_id);
    }

    public function test_a_year_with_no_trading_produces_no_entry_at_all(): void
    {
        // An entry for nil is a row that says something happened.
        $this->ledger->postManual(
            JournalDraft::manual('Setoran modal', new DateTime('2026-03-02'))
                ->debit(AccountCode::BANK, 50_000_000)
                ->kredit(AccountCode::MODAL_DISETOR, 50_000_000),
            $this->finance,
        );

        $this->closeYear(2026);

        $this->assertSame(0, JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->count());
        $this->assertNull(AccountingPeriod::query()->where('tahun', 2026)->where('bulan', 12)->sole()->closing_entry_id);
    }

    // ----------------------------------------------------------- the neraca

    public function test_the_neraca_balances_either_side_of_the_close(): void
    {
        $this->capital(50_000_000);
        $this->tradingYear(2026);

        $before = BalanceSheet::asOf(new DateTime('2026-12-31'));
        $this->assertTrue($before->isBalanced());
        $modalBefore = $before->totalModal();

        $this->closeYear(2026);

        $after = BalanceSheet::asOf(new DateTime('2026-12-31'));

        $this->assertTrue($after->isBalanced());

        // A closing entry rearranges equity; it does not create any.
        $this->assertSame($modalBefore, $after->totalModal());
    }

    public function test_the_result_moves_from_the_computed_line_into_a_real_account(): void
    {
        $this->capital(50_000_000);
        $this->tradingYear(2026);

        $before = BalanceSheet::asOf(new DateTime('2026-12-31'));
        $this->assertSame(5_000_000, $before->labaTahunBerjalan);
        $this->assertSame(0, $before->balanceOf(AccountCode::LABA_DITAHAN));

        $this->closeYear(2026);

        $after = BalanceSheet::asOf(new DateTime('2026-12-31'));

        $this->assertSame(0, $after->labaTahunBerjalan);
        $this->assertSame(5_000_000, $after->balanceOf(AccountCode::LABA_DITAHAN));
    }

    public function test_the_new_year_starts_from_nil(): void
    {
        $this->capital(50_000_000);
        $this->tradingYear(2026);
        $this->closeYear(2026);

        $this->trade('2027-02-10', 8_000_000, 5_000_000);

        $pl2027 = ProfitAndLoss::forPeriod(new DateTime('2027-01-01'), new DateTime('2027-12-31'));

        $this->assertSame(8_000_000, $pl2027->totalPendapatan());
        $this->assertSame(3_000_000, $pl2027->labaBersih());
    }

    public function test_a_closed_year_no_longer_shows_as_an_unclosed_prior_result(): void
    {
        /*
         * The line the balance sheet computes for "earlier years nobody ever
         * closed" is exactly what a close exists to make unnecessary. After
         * one, it should be nil — with the figure in Laba Ditahan instead.
         */
        $this->capital(50_000_000);
        $this->tradingYear(2026);
        $this->closeYear(2026);
        $this->trade('2027-02-10', 8_000_000, 5_000_000);

        $neraca = BalanceSheet::asOf(new DateTime('2027-06-15'));

        $this->assertSame(0, $neraca->labaDitahanBelumDitutup);
        $this->assertSame(3_000_000, $neraca->labaTahunBerjalan);
        $this->assertSame(5_000_000, $neraca->balanceOf(AccountCode::LABA_DITAHAN));
        $this->assertTrue($neraca->isBalanced());
    }

    public function test_two_years_closed_in_a_row_accumulate_in_retained_earnings(): void
    {
        $this->capital(50_000_000);
        $this->tradingYear(2026);
        $this->closeYear(2026);
        $this->tradingYear(2027, sale: 30_000_000, cost: 18_000_000, overhead: 4_000_000);

        // Only through May: June 2027 is the month we are standing in.
        $this->closeMonths(2027, 1, 5);

        // 2026's 5,000,000 is banked; 2027 is still running, and only the
        // March trading falls inside the window closed so far.
        $this->assertSame(5_000_000, $this->balance(AccountCode::LABA_DITAHAN, '2027-05-31'));
        $this->assertSame(6_000_000, BalanceSheet::asOf(new DateTime('2027-05-31'))->labaTahunBerjalan);
        $this->assertTrue(BalanceSheet::asOf(new DateTime('2027-05-31'))->isBalanced());
    }

    public function test_the_control_accounts_are_untouched_by_a_close(): void
    {
        /*
         * A closing entry moves income and expense into equity and nothing
         * else. If it ever caught a balance-sheet account, every control
         * account would drift from its subledger and no report built on them
         * would tie again.
         *
         * Asserted on the balances themselves rather than through
         * LedgerReconciliation, because this fixture posts manual journals
         * straight into Piutang and Persediaan — which the reconciliation
         * quite rightly reports as drift regardless of anything the close did.
         */
        $control = [
            AccountCode::PIUTANG_USAHA,
            AccountCode::UTANG_USAHA,
            AccountCode::PERSEDIAAN,
            AccountCode::UTANG_BELUM_DITAGIH,
            AccountCode::BANK,
            AccountCode::PPN_KELUARAN,
            AccountCode::PPN_MASUKAN,
        ];

        $this->capital(50_000_000);
        $this->tradingYear(2026);

        $before = array_map(fn ($kode) => $this->balance($kode, '2026-12-31'), $control);

        $this->closeYear(2026);

        $this->assertSame(
            array_combine($control, $before),
            array_combine($control, array_map(fn ($kode) => $this->balance($kode, '2026-12-31'), $control)),
        );

        $entry = JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->sole();
        $touched = $entry->lines->map(fn ($l) => $l->account->kode)->all();

        $this->assertSame([], array_values(array_intersect($control, $touched)));
    }

    // --------------------------------------------------------- undoing it

    public function test_reopening_december_reverses_the_closing_entry(): void
    {
        $this->capital(50_000_000);
        $this->tradingYear(2026);
        $this->closeYear(2026);

        $this->closer->reopen(2026, 12, $this->owner, 'Faktur pemasok Desember baru datang');

        $this->assertSame(20_000_000, $this->balance(AccountCode::PENJUALAN, '2026-12-31'));
        $this->assertSame(0, $this->balance(AccountCode::LABA_DITAHAN, '2026-12-31'));
        $this->assertTrue(BalanceSheet::asOf(new DateTime('2026-12-31'))->isBalanced());
    }

    public function test_the_original_close_stays_on_the_ledger_after_being_undone(): void
    {
        // Both entries remain, and both remain readable. A correction that
        // leaves no trace is indistinguishable from a cover-up.
        $this->tradingYear(2026);
        $this->closeYear(2026);
        $original = JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->sole();

        $reopening = $this->closer->reopen(2026, 12, $this->owner, 'Ada yang terlewat');

        $this->assertSame(1, JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->count());
        $this->assertSame(1, JournalEntry::query()->where('jenis', JournalEntry::JENIS_PEMBALIKAN)->count());
        $this->assertNotNull($reopening->reversal_entry_id);
        $this->assertTrue($original->fresh()->isReversed());
    }

    public function test_the_reversal_is_dated_to_the_year_it_undoes(): void
    {
        // Not to today. Undoing a 2026 close in 2027 must not put a lump of
        // 2026's revenue into 2027's income statement.
        $this->tradingYear(2026);
        $this->closeYear(2026);
        $this->closer->reopen(2026, 12, $this->owner, 'Ada yang terlewat');

        $reversal = JournalEntry::query()->where('jenis', JournalEntry::JENIS_PEMBALIKAN)->sole();

        $this->assertSame('2026-12-31', $reversal->tanggal->toDateString());
        $this->assertSame(0, ProfitAndLoss::forPeriod(
            new DateTime('2027-01-01'), new DateTime('2027-12-31')
        )->totalPendapatan());
    }

    public function test_a_year_can_be_closed_again_after_being_reopened_and_corrected(): void
    {
        $this->capital(50_000_000);
        $this->tradingYear(2026);
        $this->closeYear(2026);

        $this->closer->reopen(2026, 12, $this->owner, 'Faktur pemasok Desember baru datang');

        // The late bill everyone was waiting for.
        $this->ledger->postManual(
            JournalDraft::manual('Beban Desember yang telat', new DateTime('2026-12-28'))
                ->debit(AccountCode::BEBAN_OPERASIONAL, 1_000_000)
                ->kredit(AccountCode::UTANG_USAHA, 1_000_000),
            $this->finance,
        );

        $this->closer->close(2026, 12, $this->finance);

        $this->assertSame(4_000_000, $this->balance(AccountCode::LABA_DITAHAN, '2026-12-31'));
        $this->assertSame(0, $this->balance(AccountCode::BEBAN_OPERASIONAL, '2026-12-31'));
        $this->assertTrue(BalanceSheet::asOf(new DateTime('2026-12-31'))->isBalanced());
        $this->assertTrue($this->ledger->isBalanced());
    }

    // ------------------------------------------------------------- preview

    public function test_the_preview_is_what_closing_would_actually_post(): void
    {
        // Built from the same rule, because a preview assembled from a second
        // copy of it is a preview that can lie.
        $this->tradingYear(2026);

        $preview = $this->closer->previewYearEnd(2026);

        $this->assertNotNull($preview);
        $this->assertTrue($preview->isBalanced());
        $previewTotal = $preview->totalDebit();

        $this->closeYear(2026);

        $entry = JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->sole();

        $this->assertSame($previewTotal, $entry->total_debit_rupiah);
        $this->assertCount(count($preview->lines()), $entry->lines);
    }

    public function test_the_preview_writes_nothing(): void
    {
        $this->tradingYear(2026);

        $this->closer->previewYearEnd(2026);

        $this->assertSame(0, JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->count());
        $this->assertSame(0, AccountingPeriod::query()->count());
    }

    public function test_the_preview_of_a_year_with_nothing_in_it_is_absent_not_empty(): void
    {
        $this->assertNull($this->closer->previewYearEnd(2026));
    }

    // --- helpers ------------------------------------------------------------

    private function balance(string $kode, string $asOf): int
    {
        return $this->ledger->balanceOf($kode, new DateTime($asOf));
    }

    private function capital(int $amount): void
    {
        $this->ledger->postManual(
            JournalDraft::manual('Setoran modal', new DateTime('2026-01-02'))
                ->debit(AccountCode::BANK, $amount)
                ->kredit(AccountCode::MODAL_DISETOR, $amount),
            $this->finance,
        );
    }

    /** A year's worth of trading, spread over three months so it is not one entry. */
    private function tradingYear(
        int $tahun,
        int $sale = 20_000_000,
        int $cost = 12_000_000,
        int $overhead = 3_000_000,
    ): void {
        $this->trade("{$tahun}-03-10", intdiv($sale, 2), intdiv($cost, 2));
        $this->trade("{$tahun}-09-10", $sale - intdiv($sale, 2), $cost - intdiv($cost, 2));

        if ($overhead > 0) {
            $this->ledger->postManual(
                JournalDraft::manual('Beban operasional', new DateTime("{$tahun}-11-20"))
                    ->debit(AccountCode::BEBAN_OPERASIONAL, $overhead)
                    ->kredit(AccountCode::BANK, $overhead),
                $this->finance,
            );
        }
    }

    private function trade(string $tanggal, int $sale, int $cost): void
    {
        $this->ledger->postManual(
            JournalDraft::manual("Penjualan {$tanggal}", new DateTime($tanggal))
                ->debit(AccountCode::PIUTANG_USAHA, $sale)
                ->kredit(AccountCode::PENJUALAN, $sale),
            $this->finance,
        );

        if ($cost > 0) {
            $this->ledger->postManual(
                JournalDraft::manual("HPP {$tanggal}", new DateTime($tanggal))
                    ->debit(AccountCode::HARGA_POKOK_PENJUALAN, $cost)
                    ->kredit(AccountCode::PERSEDIAAN, $cost),
                $this->finance,
            );
        }
    }

    /** Close every month of a year, which closes the year with it. */
    private function closeYear(int $tahun): void
    {
        $this->closeMonths($tahun, 1, 12);
    }

    private function closeMonths(int $tahun, int $dari, int $sampai): void
    {
        foreach (range($dari, $sampai) as $bulan) {
            $this->closer->close($tahun, $bulan, $this->finance);
        }
    }
}
