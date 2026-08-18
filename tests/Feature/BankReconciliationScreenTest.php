<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Banking\BankReconciler;
use App\Domain\Banking\StatementDirection;
use App\Domain\Payments\PaymentLedger;
use App\Filament\Pages\Akuntansi\RekonsiliasiBank;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The reconciliation desk.
 *
 * Behind `canReconcileBank()`, which is Finance and Owner. The screen's job is
 * an hour with a statement in one hand, so what these tests check is that the
 * running difference is live, that ticking moves it, and that the way out of a
 * difference is reachable from the same screen.
 */
class BankReconciliationScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Company $pelanggan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-05 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->pelanggan = Company::factory()->creditLimit(500_000_000)->create([
            'nama' => 'CV Sinar Distribusi',
            'status' => Company::STATUS_ACTIVE,
        ]);
    }

    #[DataProvider('roles')]
    public function test_who_may_open_the_screen(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, RekonsiliasiBank::canAccess());
    }

    #[DataProvider('roles')]
    public function test_the_route_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')
            ->get(RekonsiliasiBank::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
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

    public function test_a_bank_nobody_has_ever_proven_says_so_on_the_screen(): void
    {
        /*
         * An unreconciled bank account is invisible: nothing breaks, no queue
         * fills, every report still renders. This paragraph is the only place
         * in the system that ever mentions it.
         */
        $this->actingAs($this->finance, 'web')
            ->get(RekonsiliasiBank::getUrl())
            ->assertOk()
            ->assertSee('belum pernah direkonsiliasi', false);

        $this->actingAs($this->finance);
        $this->assertSame('belum pernah', RekonsiliasiBank::getNavigationBadge());
    }

    public function test_the_badge_appears_once_a_statement_goes_stale(): void
    {
        // Five weeks: a month plus the few days a statement takes to arrive.
        $this->receive(10_000_000, '2026-08-05');
        $this->reconcile('2026-08-31', 10_000_000);

        $this->actingAs($this->finance);

        $this->travelTo('2026-09-25 09:00:00');
        $this->assertNull(RekonsiliasiBank::getNavigationBadge());

        $this->travelTo('2026-10-20 09:00:00');
        $this->assertSame('50 hari', RekonsiliasiBank::getNavigationBadge());
    }

    public function test_starting_one_asks_only_what_the_statement_says(): void
    {
        $this->receive(10_000_000, '2026-08-05');

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->callAction('mulai', [
                'tanggal_rekening' => '2026-08-31',
                'saldo_rekening_rupiah' => 9_985_000,
            ])
            ->assertHasNoActionErrors();

        $rec = BankReconciliation::query()->sole();

        $this->assertTrue($rec->isDraft());
        $this->assertSame(9_985_000, (int) $rec->saldo_rekening_rupiah);
    }

    public function test_ticking_a_line_moves_the_difference(): void
    {
        /*
         * The whole feedback loop. A difference that does not respond to
         * ticking is a screen nobody will trust enough to finish an hour on.
         */
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 10_000_000);

        $reconciler = app(BankReconciler::class);

        // Untouched, the whole receipt looks like a deposit in transit.
        $this->assertSame(-10_000_000, $reconciler->summarise($rec)->selisih);

        $line = $reconciler->candidateLines($rec)->sole();

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->call('toggle', $line->id);

        $this->assertSame(0, $reconciler->summarise($rec->refresh())->selisih);
    }

    public function test_the_same_click_unticks(): void
    {
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 10_000_000);

        $reconciler = app(BankReconciler::class);
        $line = $reconciler->candidateLines($rec)->sole();

        $page = Livewire::actingAs($this->finance)->test(RekonsiliasiBank::class);

        $page->call('toggle', $line->id);
        $this->assertSame(1, BankReconciliationLine::query()->count());

        $page->call('toggle', $line->id);
        $this->assertSame(0, BankReconciliationLine::query()->count());
    }

    public function test_the_statement_is_shown_live_rather_than_stored(): void
    {
        /*
         * A stale difference on screen while somebody is working is worse than
         * no difference at all — they would tick the last line and watch
         * nothing happen.
         */
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 10_000_000);

        $this->actingAs($this->finance, 'web')
            ->get(RekonsiliasiBank::getUrl())
            ->assertOk()
            ->assertSee('Setoran dalam perjalanan', false)
            ->assertSee('Rp 10.000.000');

        // Nothing has been written to the record: it is frozen at finalisation.
        $this->assertSame(0, (int) $rec->refresh()->saldo_buku_rupiah);
    }

    public function test_the_way_out_of_a_difference_is_on_the_same_screen(): void
    {
        /*
         * Finding a difference of fifteen thousand and being sent to the
         * journal screen to post it by hand is how a reconciliation gets
         * abandoned three months in.
         */
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 9_985_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->callAction('tambahItem', [
                'keterangan' => 'Biaya administrasi Agustus',
                'arah' => StatementDirection::Keluar->value,
                'amount_rupiah' => 15_000,
                'account' => AccountCode::BEBAN_OPERASIONAL,
                'tanggal' => '2026-08-31',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(0, app(BankReconciler::class)->summarise($rec->refresh())->selisih);
        $this->assertSame(1, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_REKONSILIASI_BANK)
            ->count());
    }

    public function test_finalising_from_the_screen_is_refused_while_anything_is_unexplained(): void
    {
        $this->receive(10_000_000, '2026-08-05');
        $this->open('2026-08-31', 9_950_000);

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->callAction('selesaikan');

        // Still a draft: the action reported the refusal rather than throwing.
        $this->assertTrue(BankReconciliation::query()->sole()->isDraft());
    }

    public function test_the_way_to_finish_is_visible_but_dead_until_the_difference_is(): void
    {
        /*
         * Not hidden, because somebody an hour into a reconciliation needs to
         * see what they are working towards — and not live either, because a
         * confirmation promising to freeze the figures, followed by a refusal,
         * tells them twice that this was going to work.
         */
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 9_985_000);

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->assertActionVisible('selesaikan')
            ->assertActionDisabled('selesaikan');

        app(BankReconciler::class)->tickAll($rec, $this->finance);
        app(BankReconciler::class)->recordStatementItem(
            $rec, 'Biaya administrasi', 15_000,
            StatementDirection::Keluar, AccountCode::BEBAN_OPERASIONAL, $this->finance,
        );

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->assertActionEnabled('selesaikan');
    }

    public function test_the_actions_that_do_not_apply_are_not_offered(): void
    {
        // With nothing in progress there is one thing to do, and with a draft
        // open there is everything except starting another.
        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->assertActionVisible('mulai')
            ->assertActionHidden('selesaikan')
            ->assertActionHidden('tambahItem')
            ->assertActionHidden('batalkan');

        $this->receive(10_000_000, '2026-08-05');
        $this->open('2026-08-31', 10_000_000);

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->assertActionHidden('mulai')
            ->assertActionVisible('selesaikan')
            ->assertActionVisible('tambahItem')
            ->assertActionVisible('batalkan');
    }

    public function test_throwing_a_draft_away_hands_its_lines_back(): void
    {
        /*
         * Safe precisely because a draft has changed nothing except which
         * lines it claimed. What it *posted* — statement items — stays, since
         * the bank really did take those fees.
         */
        $this->receive(10_000_000, '2026-08-05');
        $rec = $this->open('2026-08-31', 9_985_000);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        app(BankReconciler::class)->recordStatementItem(
            $rec, 'Biaya admin', 15_000, StatementDirection::Keluar,
            AccountCode::BEBAN_OPERASIONAL, $this->finance,
        );

        Livewire::actingAs($this->finance)
            ->test(RekonsiliasiBank::class)
            ->callAction('batalkan');

        $this->assertSame(0, BankReconciliation::query()->count());
        $this->assertSame(0, BankReconciliationLine::query()->count());

        // The fee the bank charged is still a fee the bank charged.
        $this->assertSame(1, JournalEntry::query()
            ->where('jenis', JournalEntry::JENIS_REKONSILIASI_BANK)
            ->count());
    }

    public function test_a_finished_reconciliation_shows_in_the_history(): void
    {
        $this->receive(10_000_000, '2026-08-05');
        $this->reconcile('2026-08-31', 10_000_000);

        $this->actingAs($this->finance, 'web')
            ->get(RekonsiliasiBank::getUrl())
            ->assertOk()
            ->assertSee('Sudah selesai', false)
            ->assertSee('Rp 10.000.000');
    }

    public function test_one_reconciled_today_does_not_say_nought_days_ago(): void
    {
        // Small, and the first thing anybody reads after signing one off.
        $this->travelTo('2026-08-31 16:00:00');
        $this->receive(10_000_000, '2026-08-05');
        $this->reconcile('2026-08-31', 10_000_000);

        $this->actingAs($this->finance, 'web')
            ->get(RekonsiliasiBank::getUrl())
            ->assertOk()
            ->assertSee('direkonsiliasi <strong>hari ini</strong>', false)
            ->assertDontSee('0 hari');
    }

    // --- helpers ------------------------------------------------------------

    private function open(string $date, int $balance): BankReconciliation
    {
        return app(BankReconciler::class)
            ->open(Carbon::parse($date), $balance, $this->finance);
    }

    private function reconcile(string $date, int $balance): BankReconciliation
    {
        $rec = $this->open($date, $balance);
        app(BankReconciler::class)->tickAll($rec, $this->finance);

        return app(BankReconciler::class)->finalise($rec, $this->finance);
    }

    private function receive(int $amount, string $date): void
    {
        app(PaymentLedger::class)->recordManualPayment(
            company: $this->pelanggan,
            amountRupiah: $amount,
            actor: $this->finance,
            catatan: 'Transfer masuk',
            paidAt: Carbon::parse($date),
        );
    }
}
