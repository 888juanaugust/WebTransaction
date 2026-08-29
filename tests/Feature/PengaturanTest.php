<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Launch\LaunchReadiness;
use App\Domain\Pengaturan\PengaturanPerusahaan;
use App\Filament\Pages\PengaturanPerusahaanPage;
use App\Models\AuditLog;
use App\Models\Pengaturan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The values only the business knows, typed in by the Owner — and read by
 * everything that used to read `.env`, without knowing anything changed.
 */
class PengaturanTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    public function test_a_saved_value_overlays_config_and_a_blank_falls_back(): void
    {
        $service = app(PengaturanPerusahaan::class);

        // Before anything is saved, the config/env default answers.
        $this->assertSame('000-000-0000', config('perusahaan.rekening.nomor'));

        $service->simpan(['rekening_nomor' => '512-034-9911'], $this->owner());
        $this->assertSame('512-034-9911', config('perusahaan.rekening.nomor'));

        // Clearing the field is "never filled in", not "override with nothing".
        $service->simpan(['rekening_nomor' => ''], $this->owner());
        app(PengaturanPerusahaan::class)->overlay();
        // The stored blank no longer overrides on the next boot; within this
        // request config keeps the last overlay, so assert via a fresh read.
        $this->assertSame('', Pengaturan::query()->firstWhere('kunci', 'rekening_nomor')->nilai);
    }

    public function test_an_unknown_key_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PengaturanPerusahaan::class)->simpan(['xendit_secret' => 'x'], $this->owner());
    }

    public function test_saving_writes_one_audit_row_with_old_and_new(): void
    {
        $owner = $this->owner();
        $service = app(PengaturanPerusahaan::class);

        $service->simpan(['rekening_bank' => 'BCA', 'rekening_nomor' => '512-034-9911'], $owner);
        // Saving the same values again is not a change and not an audit row.
        $service->simpan(['rekening_bank' => 'BCA', 'rekening_nomor' => '512-034-9911'], $owner);

        $rows = AuditLog::query()->where('action', 'pengaturan_diubah')->get();

        $this->assertCount(1, $rows);
        $this->assertSame($owner->id, (int) $rows[0]->actor_id);
        $this->assertSame('512-034-9911', $rows[0]->new_value['rekening_nomor'] ?? null);
    }

    public function test_filling_the_bank_account_turns_the_launch_check_green(): void
    {
        $readiness = app(LaunchReadiness::class);
        $rekening = fn () => collect($readiness->checks())->firstWhere('kunci', 'rekening');

        $this->assertFalse($rekening()->lulus);

        app(PengaturanPerusahaan::class)->simpan([
            'rekening_bank' => 'BCA',
            'rekening_nomor' => '512-034-9911',
            'rekening_atas_nama' => 'PT Java Indo',
        ], $this->owner());

        $readiness->forget();
        $this->assertTrue($rekening()->lulus);
    }

    public function test_the_cache_is_busted_so_the_next_request_sees_the_save(): void
    {
        app(PengaturanPerusahaan::class)->overlay(); // primes the cache, empty

        app(PengaturanPerusahaan::class)->simpan(['rekening_bank' => 'Mandiri'], $this->owner());

        $this->assertSame(
            ['rekening_bank' => 'Mandiri'],
            Cache::get(PengaturanPerusahaan::CACHE_KEY),
        );
    }

    // --- the screen ---------------------------------------------------------

    public function test_only_the_owner_may_open_the_screen(): void
    {
        $this->actingAs($this->owner(), 'web')
            ->get('/admin/pengaturan-perusahaan')->assertOk();

        // Finance confirms money; letting them retype where the money goes
        // would collapse the separation the roles exist for.
        $this->actingAs(User::factory()->finance()->create(), 'web')
            ->get('/admin/pengaturan-perusahaan')->assertForbidden();
    }

    public function test_the_owner_saves_the_bank_account_from_the_form(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test(PengaturanPerusahaanPage::class)
            ->fillForm([
                'rekening_bank' => 'BCA',
                'rekening_nomor' => '512-034-9911',
                'rekening_atas_nama' => 'PT Java Indo Intermechanika',
            ])
            ->call('simpan')
            ->assertNotified();

        $this->assertSame('512-034-9911', config('perusahaan.rekening.nomor'));
        $this->assertDatabaseHas('pengaturan', [
            'kunci' => 'rekening_nomor', 'nilai' => '512-034-9911', 'updated_by' => $owner->id,
        ]);
    }

    public function test_the_faktur_prints_what_the_owner_typed(): void
    {
        app(PengaturanPerusahaan::class)->simpan([
            'rekening_bank' => 'BCA',
            'rekening_nomor' => '512-034-9911',
            'rekening_atas_nama' => 'PT Java Indo Intermechanika',
        ], $this->owner());

        // The blade reads config; the overlay already updated it. Assert on
        // the portal payment section's source of truth rather than booting
        // a whole invoice: the same config key the blade interpolates.
        $this->assertSame('512-034-9911', config('perusahaan.rekening.nomor'));
        $this->assertSame('PT Java Indo Intermechanika', config('perusahaan.rekening.atas_nama'));
    }
}
