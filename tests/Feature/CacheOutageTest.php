<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Launch\LaunchCheck;
use App\Domain\Launch\LaunchReadiness;
use App\Domain\Ops\OpsAlerter;
use App\Domain\Pengaturan\PengaturanPerusahaan;
use App\Models\BackupRun;
use App\Models\Pengaturan;
use App\Models\User;
use App\Notifications\PeringatanSistem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ExplodingCacheStore;
use Tests\TestCase;

/**
 * Redis is down. What still works?
 *
 * The suite runs on the array cache, which never fails, so every test in it
 * had silently agreed that the cache always answers. On the real box it is
 * one `service redis-server stop` away from not answering — and because
 * `SESSION_DRIVER=database`, staff stay logged in and keep working while it
 * is down. That is the whole problem: nothing stops, so nothing is noticed.
 *
 * Three things were wrong on that path, and they are three different kinds
 * of wrong:
 *
 *   1. The company settings *quietly reverted to the shipped placeholders* —
 *      including the bank account printed on every faktur. No error, correct
 *      documents, wrong number.
 *   2. The launch checklist *threw*, so one unanswerable question destroyed
 *      the fifteen answerable ones.
 *   3. The alerter *died of the outage it had just detected* — the one
 *      failure mode a monitor is not allowed to have.
 *
 * Each test here fails on the code as it was before this file existed.
 */
class CacheOutageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Not a null store, and the distinction is the whole fixture.
     *
     * A null cache returns misses, which is indistinguishable from a working
     * cache that happens to be empty — every caller already handles it, by
     * definition, so it proves nothing. `phpredis` against a stopped server
     * does not return a miss; it throws `RedisException: Connection refused`,
     * from whichever method the code under test happened to call. That is
     * what this swaps in, registered as a real driver so the failure arrives
     * through the same path as the real one.
     */
    private function cacheGoesDown(): void
    {
        Cache::extend('meledak', fn () => Cache::repository(new ExplodingCacheStore));

        config([
            'cache.stores.meledak' => ['driver' => 'meledak'],
            'cache.default' => 'meledak',
        ]);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    public function test_the_cache_being_down_is_the_kind_of_down_that_throws(): void
    {
        $this->cacheGoesDown();

        // Guards the fixture itself: if this ever starts passing, every other
        // test in this file is testing a cache that works.
        $this->expectExceptionMessage(ExplodingCacheStore::MESSAGE);

        Cache::get('apa pun');
    }

    public function test_settings_still_answer_from_the_database_when_the_cache_cannot(): void
    {
        /*
         * The pinned baseline is the placeholder, and the placeholder is the
         * bug: config/perusahaan.php says of this value that a wrong number
         * here sends customer money to somebody else's account. With the
         * cache unreachable the overlay used to give up and leave exactly
         * this string answering — on the faktur, silently.
         */
        config(['perusahaan.rekening.nomor' => '000-000-0000']);

        Pengaturan::query()->create(['kunci' => 'rekening_nomor', 'nilai' => '512-034-9911']);
        Pengaturan::query()->create(['kunci' => 'rekening_bank', 'nilai' => 'BCA CABANG SURABAYA']);

        $this->cacheGoesDown();

        app(PengaturanPerusahaan::class)->overlay();

        $this->assertSame('512-034-9911', config('perusahaan.rekening.nomor'));
        $this->assertSame('BCA CABANG SURABAYA', config('perusahaan.rekening.bank'));
    }

    public function test_saving_a_setting_survives_a_cache_that_cannot_be_cleared(): void
    {
        config(['perusahaan.rekening.nomor' => '000-000-0000']);

        $owner = $this->owner();

        $this->cacheGoesDown();

        // The rows commit before the forget is attempted, so the save must
        // not be turned into an exception by the part that comes after it.
        app(PengaturanPerusahaan::class)->simpan(['rekening_nomor' => '512-034-9911'], $owner);

        $this->assertSame('512-034-9911', Pengaturan::query()->firstWhere('kunci', 'rekening_nomor')->nilai);
        $this->assertSame('512-034-9911', config('perusahaan.rekening.nomor'));
    }

    public function test_the_stored_settings_are_not_cached_forever(): void
    {
        /*
         * The bound is what makes swallowing the failed forget above safe. If
         * this were `rememberForever`, a save during an outage would leave the
         * pre-save rekening being served from the moment Redis came back until
         * somebody thought to flush by hand.
         */
        $this->assertLessThanOrEqual(300, PengaturanPerusahaan::CACHE_TTL);
        $this->assertGreaterThan(0, PengaturanPerusahaan::CACHE_TTL);
    }

    public function test_the_readiness_checklist_answers_everything_it_still_can(): void
    {
        // The staff-password check reads through the cache once per account,
        // so an install with no accounts never touches it. On the real box
        // there is always at least the Owner.
        $this->owner();

        $this->cacheGoesDown();

        $checks = app(LaunchReadiness::class)->checks();

        $byKunci = collect($checks)->keyBy(fn (LaunchCheck $c) => $c->kunci);

        // The one that reads through the cache: reported, named, not passing —
        // and told apart from a check that ran and found a problem.
        $sandi = $byKunci->get('sandi_staf');
        $this->assertNotNull($sandi, 'Pemeriksaan sandi staf hilang dari daftar');
        $this->assertFalse($sandi->lulus);
        $this->assertStringContainsString('Gagal diperiksa', (string) $sandi->temuan);
        $this->assertStringContainsString(ExplodingCacheStore::MESSAGE, (string) $sandi->temuan);
        $this->assertStringContainsString('cache/Redis', (string) $sandi->tindakan);

        // And the point of the exercise: the other answers survived it.
        foreach (['identitas_perusahaan', 'identitas_pajak', 'daftar_harga', 'rekening', 'akun_kontrol'] as $kunci) {
            $this->assertTrue($byKunci->has($kunci), "Pemeriksaan {$kunci} hilang gara-gara pemeriksaan lain gagal");
        }

        $this->assertGreaterThan(9, count($checks));
    }

    public function test_launch_check_still_prints_its_report_with_the_cache_down(): void
    {
        $this->owner();

        $this->cacheGoesDown();

        // Previously this exited 1 having printed nothing at all — the
        // exception escaped the first check that touched the cache, so the
        // person following the deploy runbook learned only that something
        // threw, not which of sixteen items were in the way.
        $this->artisan('launch:check')
            ->expectsOutputToContain('Sandi staf')
            ->expectsOutputToContain('Gagal diperiksa: '.ExplodingCacheStore::MESSAGE)
            ->expectsOutputToContain('belum beres')
            ->assertExitCode(1);
    }

    public function test_the_alerter_reports_the_outage_that_broke_its_own_throttle(): void
    {
        Notification::fake();

        $owner = $this->owner();
        BackupRun::factory()->offsite()->create();
        config(['ops.disk_gawat_persen' => 0, 'ops.disk_waspada_persen' => 0]);

        $this->cacheGoesDown();

        app(OpsAlerter::class)->sweep();

        /*
         * The throttle lives in the cache, and the cache is the thing that
         * failed — so the claim could not be recorded. Unrecorded means send:
         * a repeated mail is a nuisance, an unreported outage is why this
         * class exists. It stops repeating when the cache comes back, which is
         * the same event that ends the incident.
         */
        Notification::assertSentTo($owner, PeringatanSistem::class);
    }

    public function test_a_healthy_sweep_does_not_fail_on_a_throttle_it_cannot_clear(): void
    {
        Notification::fake();

        $this->owner();

        /*
         * The mirror case, and the reason the clear-side guard is separate:
         * here nothing is wrong except the cache — but with the cache down
         * *something is* wrong, so this asserts only that the sweep completes
         * rather than that it stays silent.
         */
        $this->cacheGoesDown();

        app(OpsAlerter::class)->sweep();

        $this->assertTrue(true, 'Sweep selesai tanpa melempar');
    }
}
