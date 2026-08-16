<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\PeriodCloser;
use App\Filament\Pages\Akuntansi\TutupBuku;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodReopening;
use App\Models\JournalEntry;
use App\Models\User;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The tutup buku screen.
 *
 * Closing a month is one click and awkward to undo, and the thing it prevents
 * is invisible until it has already happened. So as much of this is about what
 * the screen *says* before somebody commits as about whether the button works.
 */
class TutupBukuScreenTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    private User $finance;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2027-03-15 09:00:00');

        $this->ledger = app(Ledger::class);
        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->owner = User::factory()->role(Role::Owner)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- access

    #[DataProvider('roles')]
    public function test_only_finance_and_the_owner_may_open_the_screen(Role $role, bool $allowed): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->assertSame($allowed, TutupBuku::canAccess());
    }

    #[DataProvider('roles')]
    public function test_the_route_refuses_not_just_the_menu(Role $role, bool $allowed): void
    {
        $response = $this->actingAs(User::factory()->role($role)->create(), 'web')->get(TutupBuku::getUrl());

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public static function roles(): array
    {
        return [
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
            'keuangan' => [Role::Finance, true],
            'pemilik' => [Role::Owner, true],
        ];
    }

    // ------------------------------------------------------------- listing

    public function test_an_empty_ledger_says_so_rather_than_showing_an_empty_table(): void
    {
        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertSee('Belum ada periode yang ditutup')
            ->assertSee('Belum ada jurnal');
    }

    public function test_the_screen_lists_every_month_and_what_it_earned(): void
    {
        $this->trade('2027-01-10', 10_000_000, 6_000_000);

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertSee('Januari 2027')
            ->assertSee('Februari 2027')
            ->assertSee('Maret 2027')
            ->assertSee('Rp 10.000.000')   // penjualan
            ->assertSee('Rp 4.000.000');   // laba bersih
    }

    public function test_only_the_month_next_in_line_is_marked_ready(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);

        $rows = collect(Livewire::actingAs($this->finance)->test(TutupBuku::class)->instance()->getRows());

        $this->assertSame(
            ['Januari 2027'],
            $rows->where('closable', true)->pluck('label')->values()->all(),
        );
    }

    public function test_the_month_in_progress_is_never_ready(): void
    {
        $this->trade('2027-03-01', 10_000_000, 0);

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertSee('Tidak ada periode yang siap ditutup')
            ->assertSee('Bulan berjalan baru bisa ditutup setelah berakhir');
    }

    public function test_the_screen_states_how_far_the_books_are_locked(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);
        app(PeriodCloser::class)->close(2027, 1, $this->finance);

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertSee('31 Januari 2027')   // locked through
            ->assertSee('1 Februari 2027');  // open from
    }

    // ------------------------------------------------------------- closing

    public function test_closing_from_the_screen_locks_the_month(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->callAction('tutup', ['catatan' => 'Sudah direkonsiliasi'])
            ->assertHasNoActionErrors();

        $period = AccountingPeriod::query()->sole();

        $this->assertSame(2027, $period->tahun);
        $this->assertSame(1, $period->bulan);
        $this->assertSame('Sudah direkonsiliasi', $period->catatan);
        $this->assertSame($this->finance->id, $period->closed_by);
    }

    public function test_the_confirmation_says_what_closing_will_stop(): void
    {
        // Whoever clicks this needs to know it blocks documents, not just
        // journals — the failure they will actually hit is a faktur being
        // refused weeks later by somebody who never saw this screen.
        $this->trade('2027-01-10', 10_000_000, 0);

        $page = Livewire::actingAs($this->finance)->test(TutupBuku::class);
        $text = $page->instance()->confirmationText();

        $page->assertSee('Tutup Januari 2027');
        $this->assertStringContainsString('Januari 2027', $text);
        $this->assertStringContainsString('faktur', $text);
        $this->assertStringContainsString('penerimaan barang', $text);
        $this->assertStringContainsString('pembayaran', $text);
    }

    public function test_a_december_close_warns_that_it_closes_the_year(): void
    {
        $this->trade('2026-12-10', 10_000_000, 6_000_000);
        $closer = app(PeriodCloser::class);

        // Everything up to November is empty but still has to be closed first.
        foreach (range(1, 11) as $bulan) {
            $closer->close(2026, $bulan, $this->finance);
        }

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertSee('Penutupan tahun 2026')
            ->assertSee('Laba Ditahan')
            ->assertSee('Januari mulai dari nol');
    }

    public function test_the_year_end_warning_is_absent_when_the_next_month_is_not_december(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertDontSee('Penutupan tahun');
    }

    public function test_the_preview_matches_what_closing_actually_posts(): void
    {
        $this->trade('2026-12-10', 10_000_000, 6_000_000);
        $closer = app(PeriodCloser::class);

        foreach (range(1, 11) as $bulan) {
            $closer->close(2026, $bulan, $this->finance);
        }

        $page = Livewire::actingAs($this->finance)->test(TutupBuku::class);
        $preview = $page->instance()->yearEndPreview();

        $page->callAction('tutup', ['catatan' => null])->assertHasNoActionErrors();

        $entry = JournalEntry::query()->where('jenis', JournalEntry::JENIS_TUTUP_BUKU)->sole();

        $this->assertSame($preview['total'], $entry->total_debit_rupiah);
        $this->assertCount($preview['baris'], $entry->lines);
    }

    public function test_the_action_closes_whatever_is_next_at_the_moment_it_is_clicked(): void
    {
        /*
         * The button label is computed when the page renders; the month it
         * closes is computed when it is clicked. If somebody else closes
         * January in between, this closes February rather than failing on a
         * stale target — and the notification names what it actually did.
         *
         * The important property is not which month it picks but that it can
         * never skip one or close the same one twice: it always asks the
         * calendar, and the calendar only ever offers the next in line.
         */
        $this->trade('2027-01-10', 10_000_000, 0);

        $page = Livewire::actingAs($this->finance)->test(TutupBuku::class);

        app(PeriodCloser::class)->close(2027, 1, $this->finance);

        $page->callAction('tutup', ['catatan' => null])->assertHasNoActionErrors();

        $closed = AccountingPeriod::query()->orderBy('tahun')->orderBy('bulan')
            ->get()
            ->map(fn ($p) => "{$p->tahun}-{$p->bulan}")
            ->all();

        $this->assertSame(['2027-1', '2027-2'], $closed);
    }

    public function test_clicking_close_when_nothing_is_closable_writes_nothing(): void
    {
        // The button is disabled, but a disabled button is a rendering
        // decision and this is the write path.
        $this->trade('2027-03-01', 10_000_000, 0);

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->callAction('tutup', ['catatan' => null])
            ->assertHasNoActionErrors();

        $this->assertSame(0, AccountingPeriod::query()->count());
    }

    // ----------------------------------------------------------- reopening

    public function test_the_owner_can_reopen_the_most_recent_closed_month(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);
        app(PeriodCloser::class)->close(2027, 1, $this->finance);

        Livewire::actingAs($this->owner)
            ->test(TutupBuku::class)
            ->callAction('buka', ['alasan' => 'Faktur pemasok baru datang'], ['tahun' => 2027, 'bulan' => 1])
            ->assertHasNoActionErrors();

        $this->assertSame(0, AccountingPeriod::query()->count());
        $this->assertSame('Faktur pemasok baru datang', AccountingPeriodReopening::query()->sole()->alasan);
    }

    public function test_finance_is_not_offered_the_reopen_action_at_all(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);
        app(PeriodCloser::class)->close(2027, 1, $this->finance);

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertDontSee('Buka kembali');

        Livewire::actingAs($this->owner)
            ->test(TutupBuku::class)
            ->assertSee('Buka kembali');
    }

    public function test_reopening_needs_a_reason_the_form_will_not_skip(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);
        app(PeriodCloser::class)->close(2027, 1, $this->finance);

        Livewire::actingAs($this->owner)
            ->test(TutupBuku::class)
            ->callAction('buka', ['alasan' => ''], ['tahun' => 2027, 'bulan' => 1])
            ->assertHasActionErrors(['alasan' => 'required']);

        $this->assertSame(1, AccountingPeriod::query()->count());
    }

    public function test_reopenings_stay_on_the_screen_afterwards(): void
    {
        $this->trade('2027-01-10', 10_000_000, 0);
        $closer = app(PeriodCloser::class);
        $closer->close(2027, 1, $this->finance);
        $closer->reopen(2027, 1, $this->owner, 'Faktur pemasok baru datang');

        Livewire::actingAs($this->finance)
            ->test(TutupBuku::class)
            ->assertOk()
            ->assertSee('Periode yang pernah dibuka kembali')
            ->assertSee('Faktur pemasok baru datang')
            ->assertSee($this->owner->name);
    }

    // --- helpers ------------------------------------------------------------

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
}
