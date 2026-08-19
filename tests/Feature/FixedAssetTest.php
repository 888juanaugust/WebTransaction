<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\Role;
use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Accounting\TrialBalance;
use App\Domain\Assets\DepreciationGroup;
use App\Domain\Assets\DepreciationRunner;
use App\Domain\Assets\DepreciationSchedule;
use App\Domain\Assets\FixedAssetRegister;
use App\Domain\Expenses\PaidFrom;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aktiva tetap and the wear on it.
 *
 * Two things were wrong before this and both flatter the business: the neraca
 * showed no fixed assets, because buying a van had nowhere to go, and the laba
 * rugi carried no depreciation, so profit was overstated by the whole wear on
 * everything owned. The second is the expensive one — it is the figure PPh is
 * calculated on.
 */
class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private FixedAssetRegister $register;

    private DepreciationRunner $runner;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-12-15 09:00:00');

        $this->finance = User::factory()->role(Role::Finance)->create();
        $this->register = app(FixedAssetRegister::class);
        $this->runner = app(DepreciationRunner::class);
        $this->ledger = app(Ledger::class);
    }

    // ------------------------------------------------------------ acquiring

    public function test_buying_one_is_an_asset_not_an_expense(): void
    {
        $asset = $this->acquire(240_000_000, '2026-01-15', DepreciationGroup::Kelompok2);

        $this->assertSame(240_000_000, $this->ledger->balanceOf(AccountCode::AKTIVA_TETAP));
        $this->assertSame(-240_000_000, $this->ledger->balanceOf(AccountCode::BANK));

        // Nothing hit the profit and loss on the way in.
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::BEBAN_PENYUSUTAN));
        $this->assertStringStartsWith('AT-', $asset->nomor);
        $this->assertSame(96, $asset->masa_manfaat_bulan);
    }

    public function test_the_life_is_frozen_from_the_group_at_registration(): void
    {
        // An asset's life is a fact as at the day it was bought. A later change
        // to the enum must not re-depreciate things bought years ago.
        $asset = $this->acquire(48_000_000, '2026-01-15', DepreciationGroup::Kelompok1);

        $this->assertSame(48, $asset->masa_manfaat_bulan);
        $this->assertSame(DepreciationGroup::Kelompok1, $asset->kelompok);
    }

    #[DataProvider('badAcquisitions')]
    public function test_what_cannot_be_registered(int $harga, int $residu, string $tanggal): void
    {
        $this->expectException(DomainException::class);

        $this->register->acquire(
            'Percobaan', DepreciationGroup::Kelompok1, Carbon::parse($tanggal),
            $harga, PaidFrom::Bank, $this->finance, nilaiResidu: $residu,
        );
    }

    public static function badAcquisitions(): array
    {
        return [
            'nothing' => [0, 0, '2026-01-15'],
            'negative' => [-1_000_000, 0, '2026-01-15'],
            // Nothing left to depreciate is not an asset, it is a filing error.
            'residual equals cost' => [10_000_000, 10_000_000, '2026-01-15'],
            'residual above cost' => [10_000_000, 12_000_000, '2026-01-15'],
            'not yet bought' => [10_000_000, 0, '2027-06-01'],
        ];
    }

    // --------------------------------------------------------- depreciating

    public function test_a_month_of_wear_moves_from_the_asset_to_the_profit_and_loss(): void
    {
        // 240m over 96 months = 2.5m a month, exactly.
        $this->acquire(240_000_000, '2026-01-15', DepreciationGroup::Kelompok2);

        $run = $this->runner->run('2026-01', $this->finance);

        $this->assertSame(1, $run->diposting);
        $this->assertSame(2_500_000, $run->totalRupiah);

        $this->assertSame(2_500_000, $this->ledger->balanceOf(AccountCode::BEBAN_PENYUSUTAN));
        $this->assertSame(-2_500_000, $this->ledger->balanceOf(AccountCode::AKUMULASI_PENYUSUTAN));

        // Cost is untouched — that is the point of a contra account.
        $this->assertSame(240_000_000, $this->ledger->balanceOf(AccountCode::AKTIVA_TETAP));
    }

    public function test_depreciation_starts_in_the_month_it_was_bought_however_late(): void
    {
        /*
         * UU PPh Pasal 11 ayat (3): depreciation begins in the month of the
         * expenditure, as a full month. Bought on the 29th, charged for all of
         * August — no pro-rating.
         */
        $this->acquire(240_000_000, '2026-08-29', DepreciationGroup::Kelompok2);

        $run = $this->runner->run('2026-08', $this->finance);

        $this->assertSame(2_500_000, $run->totalRupiah);
    }

    public function test_nothing_is_charged_before_it_was_bought(): void
    {
        $this->acquire(240_000_000, '2026-08-01', DepreciationGroup::Kelompok2);

        $run = $this->runner->run('2026-07', $this->finance);

        $this->assertTrue($run->didNothing());
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::BEBAN_PENYUSUTAN));
    }

    public function test_running_the_same_month_twice_charges_it_once(): void
    {
        /*
         * The failure that costs money and is hardest to notice: both runs
         * succeed, and the books quietly carry twice the charge. The unique
         * index makes it impossible rather than unlikely.
         */
        $this->acquire(240_000_000, '2026-01-15', DepreciationGroup::Kelompok2);

        $this->runner->run('2026-01', $this->finance);
        $second = $this->runner->run('2026-01', $this->finance);

        $this->assertTrue($second->didNothing());
        $this->assertSame(2_500_000, $this->ledger->balanceOf(AccountCode::BEBAN_PENYUSUTAN));
        $this->assertSame(1, FixedAssetDepreciation::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('jenis', JournalEntry::JENIS_PENYUSUTAN)->count());
    }

    public function test_the_charge_is_dated_to_the_month_it_belongs_to(): void
    {
        // Posting August's charge in December would land it in the wrong laba
        // rugi and the wrong tax period.
        $this->acquire(240_000_000, '2026-08-01', DepreciationGroup::Kelompok2);

        $this->runner->run('2026-08', $this->finance);

        $row = FixedAssetDepreciation::query()->sole();

        $this->assertSame('2026-08-31', $row->tanggal->toDateString());
        $this->assertSame('2026-08', $row->periode);
    }

    public function test_a_future_month_is_refused(): void
    {
        $this->acquire(240_000_000, '2026-01-15', DepreciationGroup::Kelompok2);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/belum lewat/');

        $this->runner->run('2027-03', $this->finance);
    }

    public function test_a_period_that_is_not_a_month_is_refused(): void
    {
        $this->expectException(DomainException::class);

        $this->runner->run('Agustus 2026', $this->finance);
    }

    // ------------------------------------------------------------- rounding

    public function test_the_final_month_absorbs_the_rounding_and_lands_exactly(): void
    {
        /*
         * The reason this is not `round()`. 10,000,000 over 48 months is
         * 208,333.33; charging a rounded figure every month leaves the asset a
         * few hundred rupiah short of written off after four years, and a van
         * carried at Rp 16 forever outlives everybody who could explain it.
         */
        $asset = $this->acquire(10_000_000, '2026-01-01', DepreciationGroup::Kelompok1);

        $month = Carbon::parse('2026-01-01');

        // Run all 48 months, plus two beyond, to prove it stops.
        $this->travelTo('2030-06-15 09:00:00');

        for ($i = 0; $i < 50; $i++) {
            $this->runner->run($month->format('Y-m'), $this->finance);
            $month->addMonth();
        }

        $asset->refresh();

        $this->assertSame(10_000_000, $asset->accumulated());
        $this->assertSame(0, $asset->bookValue());
        $this->assertTrue($asset->isFullyDepreciated());

        // 48 charges, not 50 — the life ran out and nothing was charged after.
        $this->assertSame(48, FixedAssetDepreciation::query()->count());

        // And the books agree with the register.
        $this->assertSame(-10_000_000, $this->ledger->balanceOf(AccountCode::AKUMULASI_PENYUSUTAN));
    }

    public function test_an_asset_with_a_residual_stops_at_the_residual(): void
    {
        $asset = $this->acquire(
            12_000_000, '2026-01-01', DepreciationGroup::Kelompok1, residu: 2_000_000,
        );

        $this->travelTo('2030-06-15 09:00:00');
        $month = Carbon::parse('2026-01-01');

        for ($i = 0; $i < 50; $i++) {
            $this->runner->run($month->format('Y-m'), $this->finance);
            $month->addMonth();
        }

        $this->assertSame(10_000_000, $asset->refresh()->accumulated());
        $this->assertSame(2_000_000, $asset->bookValue());
    }

    public function test_the_schedule_answers_correctly_on_its_own(): void
    {
        /*
         * Asserted directly rather than only through a run, because the runner
         * also filters by acquisition date and the two guards overlap. With
         * only the end-to-end test, either layer could be removed and nothing
         * would notice — until the schedule was used somewhere the filter is
         * not.
         */
        $asset = $this->acquire(48_000_000, '2026-03-10', DepreciationGroup::Kelompok1);
        $schedule = new DepreciationSchedule($asset);

        $this->assertFalse($schedule->isDepreciableIn('2026-02'), 'before it was bought');
        $this->assertTrue($schedule->isDepreciableIn('2026-03'), 'the month it was bought');
        $this->assertTrue($schedule->isDepreciableIn('2030-02'), 'the last month of its life');
        $this->assertFalse($schedule->isDepreciableIn('2030-03'), 'one month past its life');
        $this->assertFalse($schedule->isDepreciableIn('2035-01'), 'long past its life');

        // 48 months from March 2026 ends in February 2030.
        $this->assertSame('2030-02', $schedule->lastPeriod());
    }

    // ------------------------------------------------------------ disposing

    public function test_selling_one_for_its_book_value_produces_no_gain_or_loss(): void
    {
        $asset = $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);

        // Two months of wear: 5,000,000. Book value 235,000,000.
        $this->runner->run('2026-01', $this->finance);
        $this->runner->run('2026-02', $this->finance);

        $this->register->dispose(
            $asset->refresh(), Carbon::parse('2026-03-10'), 235_000_000,
            $this->finance, 'Tukar tambah',
        );

        // Both sides of the asset are gone.
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::AKTIVA_TETAP));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::AKUMULASI_PENYUSUTAN));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::LABA_RUGI_PELEPASAN_ASET));
    }

    public function test_selling_one_cheap_is_a_loss_and_not_negative_revenue(): void
    {
        $asset = $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);
        $this->runner->run('2026-01', $this->finance);

        // Book value 237,500,000; sold for 200,000,000.
        $this->register->dispose(
            $asset->refresh(), Carbon::parse('2026-02-10'), 200_000_000,
            $this->finance, 'Rusak berat',
        );

        $this->assertSame(37_500_000, $this->ledger->balanceOf(AccountCode::LABA_RUGI_PELEPASAN_ASET));

        // Selling the old van is not trade.
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::PENJUALAN));
    }

    public function test_selling_one_well_is_a_gain_that_still_avoids_penjualan(): void
    {
        $asset = $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);
        $this->runner->run('2026-01', $this->finance);

        $this->register->dispose(
            $asset->refresh(), Carbon::parse('2026-02-10'), 250_000_000,
            $this->finance, 'Ditawar tinggi',
        );

        // A credit balance on an expense account reads negative: a gain.
        $this->assertSame(-12_500_000, $this->ledger->balanceOf(AccountCode::LABA_RUGI_PELEPASAN_ASET));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::PENJUALAN));
    }

    public function test_scrapping_one_writes_off_the_whole_book_value(): void
    {
        $asset = $this->acquire(48_000_000, '2026-01-01', DepreciationGroup::Kelompok1);
        $this->runner->run('2026-01', $this->finance);

        // 1,000,000 taken; 47,000,000 left; nothing received for it.
        $this->register->dispose(
            $asset->refresh(), Carbon::parse('2026-02-10'), 0, $this->finance, 'Dibuang, tidak terpakai',
        );

        $this->assertSame(47_000_000, $this->ledger->balanceOf(AccountCode::LABA_RUGI_PELEPASAN_ASET));
        $this->assertSame(0, $this->ledger->balanceOf(AccountCode::AKTIVA_TETAP));
    }

    public function test_nothing_is_charged_in_or_after_the_month_of_disposal(): void
    {
        /*
         * The asset was not in service for that month, and the disposal entry
         * already settles its book value — charging it as well would take the
         * wear twice.
         */
        $asset = $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);
        $this->runner->run('2026-01', $this->finance);

        $this->register->dispose(
            $asset->refresh(), Carbon::parse('2026-02-10'), 0, $this->finance, 'Dijual',
        );

        $februari = $this->runner->run('2026-02', $this->finance);
        $maret = $this->runner->run('2026-03', $this->finance);

        $this->assertTrue($februari->didNothing());
        $this->assertTrue($maret->didNothing());
        $this->assertSame(1, FixedAssetDepreciation::query()->count());
    }

    public function test_an_asset_sold_this_month_still_owes_last_month(): void
    {
        // The run has to include disposed assets, or a month closed after a
        // disposal quietly loses the charge that month was owed.
        $asset = $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);

        $this->register->dispose(
            $asset->refresh(), Carbon::parse('2026-03-10'), 0, $this->finance, 'Dijual',
        );

        $januari = $this->runner->run('2026-01', $this->finance);
        $februari = $this->runner->run('2026-02', $this->finance);

        $this->assertSame(2_500_000, $januari->totalRupiah);
        $this->assertSame(2_500_000, $februari->totalRupiah);
    }

    public function test_one_cannot_be_disposed_of_twice(): void
    {
        $asset = $this->acquire(48_000_000, '2026-01-01', DepreciationGroup::Kelompok1);

        $this->register->dispose($asset, Carbon::parse('2026-02-10'), 0, $this->finance, 'Dibuang');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/sudah dilepas/');

        $this->register->dispose($asset->refresh(), Carbon::parse('2026-03-10'), 0, $this->finance, 'Lagi');
    }

    public function test_a_disposal_must_say_why_and_cannot_predate_the_purchase(): void
    {
        $asset = $this->acquire(48_000_000, '2026-06-01', DepreciationGroup::Kelompok1);

        $this->assertRefused(fn () => $this->register->dispose(
            $asset, Carbon::parse('2026-07-01'), 0, $this->finance, '   ',
        ));

        $this->assertRefused(fn () => $this->register->dispose(
            $asset, Carbon::parse('2026-01-01'), 0, $this->finance, 'Terlalu awal',
        ));
    }

    // ------------------------------------------------- tying to the books

    public function test_both_control_accounts_tie_to_the_register(): void
    {
        $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);
        $this->acquire(48_000_000, '2026-02-01', DepreciationGroup::Kelompok1);

        $this->runner->run('2026-01', $this->finance);
        $this->runner->run('2026-02', $this->finance);
        $this->runner->run('2026-03', $this->finance);

        $this->assertControlAccountsAgree();
    }

    public function test_they_still_tie_after_a_disposal(): void
    {
        /*
         * The sign trap. Akumulasi Penyusutan is a contra-asset, so its ledger
         * balance is negative; a subledger figure computed positive would
         * report a discrepancy of exactly twice the depreciation on a system
         * working perfectly. And both sides of a disposed asset leave the
         * books together, so counting either one here would leave the check
         * permanently out by everything ever sold.
         */
        $keep = $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);
        $sell = $this->acquire(48_000_000, '2026-01-01', DepreciationGroup::Kelompok1);

        $this->runner->run('2026-01', $this->finance);
        $this->runner->run('2026-02', $this->finance);

        $this->register->dispose(
            $sell->refresh(), Carbon::parse('2026-03-05'), 40_000_000, $this->finance, 'Dijual',
        );

        $this->runner->run('2026-03', $this->finance);

        $this->assertControlAccountsAgree();

        // And the one we kept is still carried in full.
        $this->assertSame(240_000_000, $this->ledger->balanceOf(AccountCode::AKTIVA_TETAP));
        $this->assertSame($keep->refresh()->accumulated(), -$this->ledger->balanceOf(AccountCode::AKUMULASI_PENYUSUTAN));
    }

    private function assertControlAccountsAgree(): void
    {
        foreach (app(LedgerReconciliation::class)->discrepancies() as $check) {
            $this->fail(sprintf(
                '%s (%s) is out by %s: buku %s, buku pembantu %s',
                $check->nama, $check->kode, $check->selisih(), $check->buku, $check->subledger,
            ));
        }

        $this->assertTrue(true);
    }

    public function test_the_contra_account_is_not_flagged_as_a_reversed_balance(): void
    {
        /*
         * Akumulasi Penyusutan holds a credit balance for its whole life, so
         * the trial balance's "saldo terbalik" warning would sit on it every
         * month forever. A warning that can never be cleared teaches whoever
         * reads that screen to skip the label — including the month it appears
         * on Persediaan, which is the one worth stopping for.
         */
        $this->acquire(240_000_000, '2026-01-01', DepreciationGroup::Kelompok2);
        $this->runner->run('2026-01', $this->finance);

        $tb = TrialBalance::asOf(Carbon::parse('2026-01-31'));

        $akumulasi = collect($tb->rows())
            ->first(fn ($row) => $row->account->kode === AccountCode::AKUMULASI_PENYUSUTAN);

        $this->assertNotNull($akumulasi);
        $this->assertSame(-2_500_000, $akumulasi->balance());
        $this->assertFalse($akumulasi->isContrary(), 'a contra account is not a reversed balance');
    }

    // ---------------------------------------------------------------- access

    #[DataProvider('roles')]
    public function test_who_may_manage_assets(Role $role, bool $allowed): void
    {
        $actor = User::factory()->role($role)->create();

        try {
            $this->register->acquire(
                'Rak gudang', DepreciationGroup::Kelompok2, Carbon::parse('2026-01-05'),
                10_000_000, PaidFrom::Bank, $actor,
            );
            $this->assertTrue($allowed, "{$role->value} should not have been allowed");
        } catch (DomainException $e) {
            $this->assertFalse($allowed, $e->getMessage());
            $this->assertStringContainsString('tidak berhak', $e->getMessage());
        }
    }

    #[DataProvider('roles')]
    public function test_who_may_post_depreciation(Role $role, bool $allowed): void
    {
        // Separate from the register: posting a month of depreciation writes
        // to the ledger without anybody buying anything, and that is journal
        // authority rather than asset authority.
        $this->acquire(48_000_000, '2026-01-01', DepreciationGroup::Kelompok1);

        $actor = User::factory()->role($role)->create();

        try {
            $this->runner->run('2026-01', $actor);
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
            'sales' => [Role::Sales, false],
            'gudang' => [Role::Warehouse, false],
        ];
    }

    // --- helpers ------------------------------------------------------------

    private function acquire(
        int $harga,
        string $tanggal,
        DepreciationGroup $kelompok,
        int $residu = 0,
    ): FixedAsset {
        return $this->register->acquire(
            nama: 'Kendaraan operasional',
            kelompok: $kelompok,
            tanggal: Carbon::parse($tanggal),
            hargaPerolehan: $harga,
            paidFrom: PaidFrom::Bank,
            actor: $this->finance,
            kategori: 'kendaraan',
            nilaiResidu: $residu,
        );
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
