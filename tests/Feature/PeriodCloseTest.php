<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\ClosedPeriodException;
use App\Domain\Accounting\FiscalCalendar;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\PeriodCloser;
use App\Domain\Accounting\ProfitAndLoss;
use App\Domain\Accounting\TrialBalance;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodReopening;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use DateTime;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Closing the books.
 *
 * The ledger already refuses an unbalanced entry and never edits a posted row.
 * Neither of those stops what actually goes wrong: a document entered three
 * weeks late, dated to when it happened, quietly restating a month whose
 * figures have already gone to the accountant. Nothing is broken and nothing
 * is edited — last month's profit is simply a different number than it was.
 *
 * So most of these tests are about dates and ordering rather than about
 * arithmetic, and the ones that matter most are the ones where a legitimate
 * operation must still be allowed: a retry of an entry already posted, a
 * correction dated into the current month, a reversal of something closed.
 */
class PeriodCloseTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    private PeriodCloser $closer;

    private FiscalCalendar $calendar;

    private User $finance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Every date in this test is fixed relative to "now", because closing a
        // period that has not ended yet is refused — and a suite that passes in
        // January and fails in December is worse than no suite.
        Carbon::setTestNow('2027-03-15 09:00:00');

        $this->ledger = app(Ledger::class);
        $this->closer = app(PeriodCloser::class);
        $this->calendar = app(FiscalCalendar::class);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ----------------------------------------------------------- the lock

    public function test_a_closed_month_refuses_anything_dated_into_it(): void
    {
        $this->trade('2026-01-10', 10_000_000);
        $this->closer->close(2026, 1, $this->finance);

        $this->expectException(ClosedPeriodException::class);

        $this->trade('2026-01-20', 5_000_000);
    }

    public function test_the_refusal_names_the_month_and_what_to_do_about_it(): void
    {
        // Whoever hits this is entering a delivery note, not reading a ledger.
        $this->trade('2026-01-10', 10_000_000);
        $this->closer->close(2026, 1, $this->finance);

        try {
            $this->trade('2026-01-20', 5_000_000);
            $this->fail('A back-dated entry was accepted.');
        } catch (ClosedPeriodException $e) {
            $this->assertStringContainsString('Januari 2026', $e->getMessage());
            $this->assertStringContainsString('20 Januari 2026', $e->getMessage());
            $this->assertStringContainsString('membuka kembali', $e->getMessage());
        }
    }

    public function test_an_open_month_after_a_closed_one_still_accepts_entries(): void
    {
        $this->trade('2026-01-10', 10_000_000);
        $this->closer->close(2026, 1, $this->finance);

        $this->trade('2026-02-05', 7_000_000);

        $this->assertSame(7_000_000, $this->ledger->balanceOf(AccountCode::PENJUALAN, new DateTime('2026-02-28')) - 10_000_000);
    }

    public function test_a_document_posting_is_refused_the_same_way_a_manual_one_is(): void
    {
        // The guard lives in Ledger, not in the manual-journal path, so an
        // invoice back-dated into a closed month fails with the document.
        $this->trade('2026-01-10', 10_000_000);
        $this->closer->close(2026, 1, $this->finance);

        $this->expectException(ClosedPeriodException::class);

        $this->ledger->post(
            JournalDraft::system(JournalEntry::JENIS_PENJUALAN, 'Faktur telat', new DateTime('2026-01-31'))
                ->debit(AccountCode::PIUTANG_USAHA, 1_000_000)
                ->kredit(AccountCode::PENJUALAN, 1_000_000)
        );
    }

    public function test_a_retry_of_an_entry_already_posted_is_not_refused_by_a_later_close(): void
    {
        /*
         * The check sits after the idempotency look-up on purpose. A queue job
         * retried in March, for a document already posted in January, must
         * hand back what is there — the entry exists and nothing is being
         * back-dated. Checking first would turn every closed month into a
         * minefield for retries.
         */
        $company = Company::factory()->create();

        $draft = fn () => JournalDraft::for($company, JournalEntry::JENIS_PENJUALAN, 'Faktur', new DateTime('2026-01-10'))
            ->debit(AccountCode::PIUTANG_USAHA, 3_000_000)
            ->kredit(AccountCode::PENJUALAN, 3_000_000);

        $first = $this->ledger->post($draft());
        $this->closer->close(2026, 1, $this->finance);

        $again = $this->ledger->post($draft());

        $this->assertTrue($first->is($again));
        $this->assertSame(1, JournalEntry::query()->where('jenis', JournalEntry::JENIS_PENJUALAN)->count());
    }

    public function test_a_correction_for_a_closed_month_lands_in_the_open_one(): void
    {
        // Reversing something that happened in a closed month is ordinary and
        // must work. What must not is dating the reversal back into it.
        $this->trade('2026-01-10', 10_000_000);
        $entry = JournalEntry::query()->latest('id')->firstOrFail();
        $this->closer->close(2026, 1, $this->finance);

        $reversal = $this->ledger->reverse($entry, $this->finance, 'Salah pelanggan');

        $this->assertSame('2027-03-15', $reversal->tanggal->toDateString());
        $this->assertTrue($this->ledger->isBalanced());
    }

    public function test_a_reversal_cannot_be_back_dated_into_the_closed_month(): void
    {
        $this->trade('2026-01-10', 10_000_000);
        $entry = JournalEntry::query()->latest('id')->firstOrFail();
        $this->closer->close(2026, 1, $this->finance);

        $this->expectException(ClosedPeriodException::class);

        $this->ledger->reverse($entry, $this->finance, 'Salah', new DateTime('2026-01-31'));
    }

    public function test_closing_leaves_the_figures_it_locked_exactly_as_they_were(): void
    {
        $this->trade('2026-01-10', 10_000_000);
        $before = TrialBalance::asOf(new DateTime('2026-01-31'))->totalDebit();

        $this->closer->close(2026, 1, $this->finance);

        $this->assertSame($before, TrialBalance::asOf(new DateTime('2026-01-31'))->totalDebit());
    }

    // -------------------------------------------------------------- order

    public function test_months_close_oldest_first(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->trade('2026-02-10', 1_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Tutup Januari 2026 dulu');

        $this->closer->close(2026, 2, $this->finance);
    }

    public function test_a_month_with_no_trading_in_it_still_has_to_be_closed_in_turn(): void
    {
        // Skipping an empty month would make the ordering rule look arbitrary
        // when it refused the month after it.
        $this->trade('2026-01-10', 1_000_000);
        $this->trade('2026-03-10', 1_000_000);

        $this->closer->close(2026, 1, $this->finance);

        $this->expectExceptionMessage('Tutup Februari 2026 dulu');

        $this->closer->close(2026, 3, $this->finance);
    }

    public function test_closing_in_order_works_all_the_way_along(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->trade('2026-02-10', 1_000_000);
        $this->trade('2026-03-10', 1_000_000);

        foreach ([1, 2, 3] as $bulan) {
            $this->closer->close(2026, $bulan, $this->finance);
        }

        $this->assertSame(3, AccountingPeriod::query()->count());
        $this->assertSame('2026-04-01', $this->calendar->openFrom()->toDateString());
    }

    public function test_the_month_you_are_standing_in_cannot_be_closed(): void
    {
        // Closing the current month locks out the rest of it.
        $this->trade('2027-03-01', 1_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('belum berakhir');

        $this->closeThrough(2027, 3);
    }

    public function test_a_month_cannot_be_closed_twice(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->closer->close(2026, 1, $this->finance);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sudah ditutup');

        $this->closer->close(2026, 1, $this->finance);
    }

    public function test_a_month_that_does_not_exist_is_refused(): void
    {
        $this->expectException(LogicException::class);

        $this->closer->close(2026, 13, $this->finance);
    }

    // ------------------------------------------------------------ reopening

    public function test_months_reopen_newest_first(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->trade('2026-02-10', 1_000_000);
        $this->closer->close(2026, 1, $this->finance);
        $this->closer->close(2026, 2, $this->finance);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Buka Februari 2026 dulu');

        $this->closer->reopen(2026, 1, $this->owner, 'Ada faktur ketinggalan');
    }

    public function test_reopening_lets_the_month_accept_entries_again(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->closer->close(2026, 1, $this->finance);

        $this->closer->reopen(2026, 1, $this->owner, 'Faktur pemasok baru datang');

        $this->trade('2026-01-25', 4_000_000);

        $this->assertTrue($this->calendar->isOpen(new DateTime('2026-01-25')));
        $this->assertSame(5_000_000, ProfitAndLoss::forPeriod(
            new DateTime('2026-01-01'), new DateTime('2026-01-31')
        )->totalPendapatan());
    }

    public function test_reopening_a_month_that_was_never_closed_is_refused(): void
    {
        $this->trade('2026-01-10', 1_000_000);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tidak sedang ditutup');

        $this->closer->reopen(2026, 1, $this->owner, 'Alasan');
    }

    public function test_reopening_needs_a_reason(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->closer->close(2026, 1, $this->finance);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('harus disertai alasan');

        $this->closer->reopen(2026, 1, $this->owner, '   ');
    }

    public function test_every_reopening_is_recorded_rather_than_overwriting_the_last(): void
    {
        $this->trade('2026-01-10', 1_000_000);

        foreach (['Pertama', 'Kedua'] as $alasan) {
            $this->closer->close(2026, 1, $this->finance);
            $this->closer->reopen(2026, 1, $this->owner, $alasan);
        }

        $this->assertSame(2, AccountingPeriodReopening::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'accounting_period_reopened')->count());
    }

    // ------------------------------------------------------------ authority

    #[DataProvider('roles')]
    public function test_only_finance_and_the_owner_may_close(Role $role, bool $mayClose, bool $mayReopen): void
    {
        $this->trade('2026-01-10', 1_000_000);

        if (! $mayClose) {
            $this->expectException(DomainException::class);
        }

        $period = $this->closer->close(2026, 1, User::factory()->role($role)->create());

        $this->assertSame(2026, $period->tahun);
    }

    #[DataProvider('roles')]
    public function test_only_the_owner_may_reopen(Role $role, bool $mayClose, bool $mayReopen): void
    {
        /*
         * Deliberately narrower than closing. Reopening is how a set of
         * figures already sent to the accountant gets quietly restated, so
         * Finance closing a month cannot also undo it on their own.
         */
        $this->trade('2026-01-10', 1_000_000);
        $this->closer->close(2026, 1, $this->finance);

        if (! $mayReopen) {
            $this->expectException(DomainException::class);
        }

        $reopening = $this->closer->reopen(2026, 1, User::factory()->role($role)->create(), 'Alasan');

        $this->assertSame(2026, $reopening->tahun);
    }

    public function test_finance_can_close_but_cannot_undo_its_own_close(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->closer->close(2026, 1, $this->finance);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Hanya pemilik');

        $this->closer->reopen(2026, 1, $this->finance, 'Berubah pikiran');
    }

    public static function roles(): array
    {
        return [
            //                        close, reopen
            'sales' => [Role::Sales, false, false],
            'gudang' => [Role::Warehouse, false, false],
            'keuangan' => [Role::Finance, true, false],
            'pemilik' => [Role::Owner, true, true],
        ];
    }

    public function test_closing_and_reopening_are_both_audited(): void
    {
        $this->trade('2026-01-10', 1_000_000);
        $this->closer->close(2026, 1, $this->finance, 'Sudah direkonsiliasi');
        $this->closer->reopen(2026, 1, $this->owner, 'Faktur pemasok baru datang');

        $closed = AuditLog::query()->where('action', 'accounting_period_closed')->sole();
        $reopened = AuditLog::query()->where('action', 'accounting_period_reopened')->sole();

        $this->assertSame('2026-01', $closed->new_value['periode']);
        $this->assertSame('Sudah direkonsiliasi', $closed->alasan);
        $this->assertSame('Faktur pemasok baru datang', $reopened->alasan);
        $this->assertSame($this->owner->id, $reopened->actor_id);
    }

    // ------------------------------------------------------------ calendar

    public function test_nothing_is_closable_until_a_month_has_something_in_it(): void
    {
        $this->assertNull($this->calendar->nextToClose());
        $this->assertSame([], $this->calendar->months());
        $this->assertNull($this->calendar->openFrom());
    }

    public function test_the_next_month_to_close_follows_the_last_one_closed(): void
    {
        $this->trade('2026-01-10', 1_000_000);

        $this->assertSame('2026-01-01', $this->calendar->nextToClose()->toDateString());

        $this->closer->close(2026, 1, $this->finance);

        $this->assertSame('2026-02-01', $this->calendar->nextToClose()->toDateString());
    }

    public function test_the_current_month_is_never_offered_as_the_next_to_close(): void
    {
        $this->trade('2026-01-10', 1_000_000);

        foreach (range(1, 12) as $bulan) {
            $this->closer->close(2026, $bulan, $this->finance);
        }
        $this->closer->close(2027, 1, $this->finance);
        $this->closer->close(2027, 2, $this->finance);

        // March 2027 is where "now" is. Closing it would lock the rest of it.
        $this->assertNull($this->calendar->nextToClose());
    }

    public function test_the_calendar_lists_every_month_from_the_first_entry_to_now(): void
    {
        $this->trade('2026-11-10', 1_000_000);

        $months = array_map(fn ($m) => $m->format('Y-m'), $this->calendar->months());

        $this->assertSame(
            ['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'],
            $months,
        );
    }

    // --- helpers ------------------------------------------------------------

    /** A sale on a given date, posted as a manual journal. */
    private function trade(string $tanggal, int $amount): void
    {
        $this->ledger->postManual(
            JournalDraft::manual("Penjualan {$tanggal}", new DateTime($tanggal))
                ->debit(AccountCode::PIUTANG_USAHA, $amount)
                ->kredit(AccountCode::PENJUALAN, $amount),
            $this->finance,
        );
    }

    private function closeThrough(int $tahun, int $bulan): void
    {
        foreach ($this->calendar->months() as $month) {
            if ($month->year > $tahun || ($month->year === $tahun && $month->month > $bulan)) {
                break;
            }

            $this->closer->close($month->year, $month->month, $this->finance);
        }
    }
}
