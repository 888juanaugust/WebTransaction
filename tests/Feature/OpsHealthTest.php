<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ops\OpsAlerter;
use App\Domain\Ops\OpsCheck;
use App\Domain\Ops\OpsHealth;
use App\Domain\Ops\OpsStatus;
use App\Filament\Widgets\KesehatanSistem;
use App\Models\BackupRun;
use App\Models\User;
use App\Notifications\PeringatanSistem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The box's self-examination: seven checks, an exit code, a widget that
 * only speaks when something is wrong, and one email per incident.
 */
class OpsHealthTest extends TestCase
{
    use RefreshDatabase;

    private function healthy(): void
    {
        Cache::put(OpsHealth::HEARTBEAT_KEY, time());
        BackupRun::factory()->offsite()->create();

        // The machine running the suite decides its own disk; pin the
        // thresholds so this fixture means what it says everywhere.
        config(['ops.disk_gawat_persen' => 0, 'ops.disk_waspada_persen' => 0]);
    }

    public function test_a_healthy_box_reports_seven_green_checks(): void
    {
        $this->healthy();

        $health = app(OpsHealth::class);

        $this->assertCount(7, $health->checks());
        $this->assertSame(OpsStatus::Sehat, $health->worst());
        $this->assertSame([], $health->failing());
    }

    public function test_a_missing_heartbeat_is_gawat_and_says_what_it_means(): void
    {
        $this->healthy();
        Cache::forget(OpsHealth::HEARTBEAT_KEY);

        $failing = app(OpsHealth::class)->failing();

        $this->assertCount(1, $failing);
        $this->assertSame('scheduler', $failing[0]->kunci);
        $this->assertSame(OpsStatus::Gawat, $failing[0]->status);
        $this->assertStringContainsString('reservasi basi', $failing[0]->temuan);
    }

    public function test_a_stale_heartbeat_warns_before_it_alarms(): void
    {
        $this->healthy();

        Cache::put(OpsHealth::HEARTBEAT_KEY, time() - 6 * 60);
        $this->assertSame(OpsStatus::Waspada, app(OpsHealth::class)->worst());

        Cache::put(OpsHealth::HEARTBEAT_KEY, time() - 20 * 60);
        $this->assertSame(OpsStatus::Gawat, app(OpsHealth::class)->worst());
    }

    public function test_backups_age_from_warning_into_incident(): void
    {
        Cache::put(OpsHealth::HEARTBEAT_KEY, time());

        // No verified offsite backup at all: gawat.
        $cadangan = $this->check('cadangan');
        $this->assertSame(OpsStatus::Gawat, $cadangan->status);

        // One missed night: warning.
        BackupRun::factory()->offsite()->create();
        BackupRun::query()->latest('id')->first()
            ->forceFill(['created_at' => now()->subHours(30)])->save();
        $this->assertSame(OpsStatus::Waspada, $this->check('cadangan')->status);

        // Two missed nights: incident.
        BackupRun::query()->latest('id')->first()
            ->forceFill(['created_at' => now()->subHours(51)])->save();
        $this->assertSame(OpsStatus::Gawat, $this->check('cadangan')->status);
    }

    public function test_a_nearly_full_disk_escalates(): void
    {
        $this->healthy();

        config(['ops.disk_waspada_persen' => 101, 'ops.disk_gawat_persen' => 0]);
        $this->assertSame(OpsStatus::Waspada, $this->check('disk')->status);

        config(['ops.disk_gawat_persen' => 101]);
        $this->assertSame(OpsStatus::Gawat, $this->check('disk')->status);
    }

    public function test_piling_failed_jobs_escalate(): void
    {
        $this->healthy();

        DB::table('failed_jobs')->insert([
            'uuid' => 'uji-1', 'connection' => 'redis', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $this->assertSame(OpsStatus::Waspada, $this->check('pekerjaan_gagal')->status);

        for ($i = 2; $i <= 11; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => "uji-{$i}", 'connection' => 'redis', 'queue' => 'default',
                'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
            ]);
        }

        $this->assertSame(OpsStatus::Gawat, $this->check('pekerjaan_gagal')->status);
    }

    // --- the command --------------------------------------------------------

    public function test_the_command_exit_code_is_the_worst_status(): void
    {
        $this->healthy();
        $this->artisan('ops:check')->assertExitCode(0);

        Cache::put(OpsHealth::HEARTBEAT_KEY, time() - 6 * 60);
        $this->artisan('ops:check')->assertExitCode(1);

        Cache::forget(OpsHealth::HEARTBEAT_KEY);
        $this->artisan('ops:check')
            ->expectsOutputToContain('Scheduler')
            ->assertExitCode(2);
    }

    // --- the widget ---------------------------------------------------------

    public function test_the_widget_is_silent_when_healthy_and_owner_only_when_not(): void
    {
        $this->healthy();

        $this->actingAs(User::factory()->owner()->create());
        $this->assertFalse(KesehatanSistem::canView());

        Cache::forget(OpsHealth::HEARTBEAT_KEY);
        $this->assertTrue(KesehatanSistem::canView());

        // Finance cannot fix a dead cron; showing them the banner is noise.
        $this->actingAs(User::factory()->finance()->create());
        $this->assertFalse(KesehatanSistem::canView());
    }

    // --- the alert ----------------------------------------------------------

    public function test_the_alert_mails_the_owner_once_per_incident(): void
    {
        Notification::fake();

        $owner = User::factory()->owner()->create();
        User::factory()->finance()->create();

        $this->healthy();
        Cache::forget(OpsHealth::HEARTBEAT_KEY); // gawat

        app(OpsAlerter::class)->sweep();
        app(OpsAlerter::class)->sweep(); // an hour later, still broken

        Notification::assertSentToTimes($owner, PeringatanSistem::class, 1);
        Notification::assertCount(1); // and to nobody but the owner
    }

    public function test_recovery_rearms_the_alert_for_the_next_incident(): void
    {
        Notification::fake();

        $owner = User::factory()->owner()->create();

        $this->healthy();
        Cache::forget(OpsHealth::HEARTBEAT_KEY);
        app(OpsAlerter::class)->sweep(); // incident #1: mails

        Cache::put(OpsHealth::HEARTBEAT_KEY, time());
        app(OpsAlerter::class)->sweep(); // healthy: clears the throttle

        Cache::forget(OpsHealth::HEARTBEAT_KEY);
        app(OpsAlerter::class)->sweep(); // incident #2: mails again

        Notification::assertSentToTimes($owner, PeringatanSistem::class, 2);
    }

    private function check(string $kunci): OpsCheck
    {
        foreach (app(OpsHealth::class)->checks() as $check) {
            if ($check->kunci === $kunci) {
                return $check;
            }
        }

        $this->fail("No check named {$kunci}");
    }
}
