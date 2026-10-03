<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Access\TeamAssigner;
use App\Domain\Credit\DebtAging;
use App\Domain\Pengaturan\Fitur;
use App\Domain\Pengaturan\PengaturanPerusahaan;
use App\Domain\Pengaturan\Preferensi;
use App\Filament\Pages\Akuntansi\FakturPajak;
use App\Filament\Pages\Preferensi as PreferensiPage;
use App\Filament\Resources\StoreVisits\StoreVisitResource;
use App\Jobs\SweepDebtAging;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Pengaturan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ACCURATE parity, Phase 1: the business's Preferensi, and the switches over
 * what WebTransaction does that ACCURATE does not.
 *
 * The rule every test here leans on: a switch left alone is today's
 * behaviour. The rest of the suite runs with every switch untouched and must
 * not notice this class exists.
 */
class PreferensiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();

        // Pin the defaults instead of trusting the ambient .env (see
        // PengaturanTest for the seven red pushes that taught this).
        config(['penjualan.debt_notice_days' => 120, 'penjualan.debt_freeze_days' => 150]);
    }

    private function preferensi(): Preferensi
    {
        return app(Preferensi::class);
    }

    private function matikan(Fitur ...$fitur): void
    {
        $this->preferensi()->simpan(
            array_fill_keys(array_map(fn (Fitur $f) => $f->value, $fitur), false),
            [],
            $this->owner,
        );
    }

    /** A customer with a team, so the sweep has somebody to tell. */
    private function pelangganDenganTim(): Company
    {
        $pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $assigner = app(TeamAssigner::class);
        $assigner->assignSales($pelanggan, User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]), $this->owner);
        $assigner->assignMarketing($pelanggan, User::factory()->marketing()->create(['region_id' => $this->currentRegion()->id]), $this->owner);

        return $pelanggan;
    }

    private function fakturBerumur(Company $pelanggan, int $hari): Invoice
    {
        return Invoice::factory()->totalling(2_000_000)->create([
            'company_id' => $pelanggan->id,
            'issued_on' => today()->subDays($hari),
            'due_date' => today()->subDays($hari - 30),
        ]);
    }

    // --- the switches themselves -------------------------------------------

    public function test_every_switch_is_on_until_someone_turns_it_off(): void
    {
        foreach (Fitur::cases() as $fitur) {
            $this->assertTrue($fitur->aktif(), "{$fitur->value} harus menyala tanpa disentuh");
        }
    }

    public function test_a_switch_whose_phase_has_not_landed_cannot_be_turned_off(): void
    {
        // Through the front door: refused, naming the phase.
        try {
            $this->preferensi()->simpan([Fitur::ReservasiStok->value => false], [], $this->owner);
            $this->fail('A switch with no code behind it was saved as off.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('fase 12', $e->getMessage());
        }

        // Through the back door: a stored "off" is ignored, because nothing
        // would honour it and the screen would be lying.
        Pengaturan::query()->create(['kunci' => Preferensi::PREFIX.Fitur::ReservasiStok->value, 'nilai' => '0']);
        $this->preferensi()->lupakan();
        app('cache')->flush();

        $this->assertTrue(Fitur::ReservasiStok->aktif());
    }

    public function test_an_unknown_switch_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->preferensi()->simpan(['tidak_ada' => false], [], $this->owner);
    }

    public function test_turning_a_switch_off_and_on_is_audited_each_time(): void
    {
        $this->matikan(Fitur::BekuKredit);
        $this->assertFalse(Fitur::BekuKredit->aktif());

        $this->preferensi()->simpan([Fitur::BekuKredit->value => true], [], $this->owner);
        $this->assertTrue(Fitur::BekuKredit->aktif());

        $rows = AuditLog::query()->where('action', 'preferensi_diubah')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['pref.beku_kredit' => '0'], $rows[0]->new_value);
        $this->assertSame(['pref.beku_kredit' => '0'], $rows[1]->old_value);
        $this->assertSame(['pref.beku_kredit' => '1'], $rows[1]->new_value);

        // Saving what is already there writes nothing and audits nothing.
        $this->preferensi()->simpan([Fitur::BekuKredit->value => true], [], $this->owner);
        $this->assertSame(2, AuditLog::query()->where('action', 'preferensi_diubah')->count());
    }

    public function test_preferences_never_collide_with_the_company_settings(): void
    {
        // Same table, same cache key, separate prefixes: the company overlay
        // walks every stored row and must ignore the pref.* ones.
        $this->matikan(Fitur::Komisi);

        app(PengaturanPerusahaan::class)->overlay();

        $this->assertFalse(Fitur::Komisi->aktif());
        $this->assertDatabaseHas('pengaturan', ['kunci' => 'pref.komisi', 'nilai' => '0']);
    }

    // --- the debt-age numbers -----------------------------------------------

    public function test_the_numbers_default_to_config_and_return_to_it_when_cleared(): void
    {
        $this->assertSame(120, $this->preferensi()->angka('piutang_hari_peringatan'));
        $this->assertSame(150, $this->preferensi()->angka('piutang_hari_beku'));

        $this->preferensi()->simpan([], ['piutang_hari_peringatan' => 90, 'piutang_hari_beku' => 120], $this->owner);
        $this->assertSame(90, app(DebtAging::class)->noticeDays());
        $this->assertSame(120, app(DebtAging::class)->freezeDays());

        $this->preferensi()->simpan([], ['piutang_hari_peringatan' => null, 'piutang_hari_beku' => ''], $this->owner);
        $this->assertSame(120, app(DebtAging::class)->noticeDays());
        $this->assertSame(150, app(DebtAging::class)->freezeDays());
    }

    public function test_the_freeze_must_come_after_the_reminder(): void
    {
        try {
            $this->preferensi()->simpan([], ['piutang_hari_beku' => 100], $this->owner);
            $this->fail('A freeze before the reminder was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('angka.piutang_hari_beku', $e->errors());
        }

        // Also when the reminder is the one being moved, and when one side
        // is cleared back to a default that would cross the other.
        $this->preferensi()->simpan([], ['piutang_hari_peringatan' => 60, 'piutang_hari_beku' => 100], $this->owner);

        $this->expectException(ValidationException::class);
        $this->preferensi()->simpan([], ['piutang_hari_peringatan' => null], $this->owner);
    }

    public function test_a_number_out_of_bounds_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->preferensi()->simpan([], ['piutang_hari_beku' => 0], $this->owner);
    }

    public function test_a_longer_freeze_age_unfreezes_a_customer_today(): void
    {
        $pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $this->fakturBerumur($pelanggan, 160);

        $this->assertTrue(app(DebtAging::class)->isFrozen($pelanggan));

        $this->preferensi()->simpan([], ['piutang_hari_beku' => 200], $this->owner);

        $this->assertFalse(app(DebtAging::class)->isFrozen($pelanggan));
    }

    // --- the switches at work -----------------------------------------------

    public function test_with_the_freeze_off_an_aged_debt_freezes_nobody(): void
    {
        $pelanggan = Company::factory()->creditLimit(500_000_000)->create();
        $this->fakturBerumur($pelanggan, 400);

        $this->matikan(Fitur::BekuKredit);

        $aging = app(DebtAging::class);
        $this->assertFalse($aging->isFrozen($pelanggan));
        $this->assertTrue($aging->fallDueInvoices($pelanggan)->isEmpty());
    }

    public function test_with_the_freeze_off_the_reminder_promises_no_lock(): void
    {
        $pelanggan = $this->pelangganDenganTim();
        $this->fakturBerumur($pelanggan, 160);

        $this->matikan(Fitur::BekuKredit);
        (new SweepDebtAging)->handle(app(DebtAging::class));

        $badan = (string) DB::table('notifications')->value('data');
        $this->assertNotSame('', $badan, 'the reminder still goes');
        $this->assertStringNotContainsString('terkunci', $badan);
    }

    public function test_with_reminders_off_the_sweep_tells_nobody_and_marks_nothing(): void
    {
        $pelanggan = $this->pelangganDenganTim();
        $faktur = $this->fakturBerumur($pelanggan, 130);

        $this->matikan(Fitur::PeringatanPiutang);
        (new SweepDebtAging)->handle(app(DebtAging::class));

        $this->assertSame(0, DB::table('notifications')->count());
        // Unmarked, so switching reminders back on still tells the team.
        $this->assertNull($faktur->fresh()->debt_notified_at);
    }

    public function test_the_reminder_says_the_age_the_business_chose(): void
    {
        $pelanggan = $this->pelangganDenganTim();
        $this->fakturBerumur($pelanggan, 95);

        $this->preferensi()->simpan([], ['piutang_hari_peringatan' => 90], $this->owner);
        (new SweepDebtAging)->handle(app(DebtAging::class));

        $this->assertStringContainsString('melewati 90 hari', (string) DB::table('notifications')->value('data'));
    }

    public function test_commission_screens_leave_the_menu_with_their_switch(): void
    {
        $this->actingAs($this->owner, 'web')->get('/admin/komisi-target')->assertOk();

        $this->matikan(Fitur::Komisi);

        $this->actingAs($this->owner, 'web')->get('/admin/komisi-target')->assertForbidden();
        $this->actingAs($this->owner, 'web')->get('/admin/laporan/komisi')->assertForbidden();
    }

    public function test_store_visits_leave_with_their_switch(): void
    {
        $sales = User::factory()->sales()->create(['region_id' => $this->currentRegion()->id]);
        $this->actingAs($sales, 'web')->get(StoreVisitResource::getUrl())->assertOk();

        $this->matikan(Fitur::KunjunganToko);

        $this->actingAs($sales, 'web')->get(StoreVisitResource::getUrl())->assertForbidden();
    }

    public function test_the_coretax_screen_leaves_with_its_switch(): void
    {
        $this->actingAs($this->owner, 'web');
        $this->assertTrue(FakturPajak::canAccess());

        $this->matikan(Fitur::EksporCoretax);

        $this->assertFalse(FakturPajak::canAccess());

        $this->actingAs($this->owner, 'web')->get('/admin/akuntansi/faktur-pajak')->assertForbidden();
    }

    // --- the screen ---------------------------------------------------------

    public function test_only_the_owner_opens_preferensi(): void
    {
        $this->actingAs($this->owner, 'web')->get('/admin/preferensi')
            ->assertOk()
            ->assertSee('Bekukan pelanggan dengan piutang terlalu tua')
            // Switches still to come are named, not offered.
            ->assertSee('Pesan stok saat order disetujui — berlaku mulai fase 12', false);

        $this->actingAs(User::factory()->finance()->create(), 'web')->get('/admin/preferensi')->assertForbidden();
    }

    public function test_the_owner_switches_the_freeze_off_and_moves_the_reminder_from_the_screen(): void
    {
        Livewire::actingAs($this->owner)
            ->test(PreferensiPage::class)
            ->assertSet('data.fitur_beku_kredit', true)
            // Blank: the number follows its default until someone sets one.
            ->assertSet('data.angka_piutang_hari_beku', null)
            ->set('data.fitur_beku_kredit', false)
            ->set('data.angka_piutang_hari_peringatan', 100)
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertNotified();

        $this->preferensi()->lupakan();
        $this->assertFalse(Fitur::BekuKredit->aktif());
        $this->assertSame(100, $this->preferensi()->angka('piutang_hari_peringatan'));

        // One audit row naming exactly the two things the Owner changed —
        // not every switch the form happened to submit.
        $this->assertEquals(
            ['pref.beku_kredit' => '0', 'pref.piutang_hari_peringatan' => '100'],
            AuditLog::query()->where('action', 'preferensi_diubah')->sole()->new_value,
        );
        // The untouched number was not pinned: it still follows config.
        $this->assertNull($this->preferensi()->angkaTersimpan('piutang_hari_beku'));
        $this->assertDatabaseMissing('pengaturan', ['kunci' => 'pref.piutang_hari_beku']);
    }

    public function test_the_screen_refuses_a_freeze_before_the_reminder(): void
    {
        Livewire::actingAs($this->owner)
            ->test(PreferensiPage::class)
            ->set('data.angka_piutang_hari_beku', 100)
            ->call('simpan')
            ->assertHasErrors(['data.angka_piutang_hari_beku']);

        $this->assertSame(150, $this->preferensi()->angka('piutang_hari_beku'));
    }
}
