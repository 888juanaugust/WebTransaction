<?php

namespace Tests\Feature\Client\Ops;

use App\Client\Domain\Ops\Health\OpsAlerter;
use App\Client\Domain\Ops\Health\OpsCheck;
use App\Client\Domain\Ops\Health\OpsHealth;
use App\Client\Domain\Ops\Health\OpsStatus;
use App\Client\Mail\SystemAlertMessage;
use App\Client\Models\BackupRun;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The seven checks, their thresholds, the command's exit code, and the alert that mails once per incident and re-arms on recovery. */
class OpsHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function healthy(): void
    {
        OpsHealth::beat();
        BackupRun::query()->create(['started_at' => now(), 'finished_at' => now(), 'status' => BackupRun::VERIFIED, 'disk' => 's3', 'offsite' => true]);
        config(['ops.health.disk_critical_percent' => 0, 'ops.health.disk_warning_percent' => 0]);
    }

    private function check(string $key): OpsCheck
    {
        foreach (app(OpsHealth::class)->checks() as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }
        $this->fail("no check {$key}");
    }

    public function test_a_healthy_box_reports_seven_green_checks_and_the_command_exits_zero(): void
    {
        $this->healthy();
        $health = app(OpsHealth::class);
        $this->assertCount(7, $health->checks());
        $this->assertSame(OpsStatus::Healthy, $health->worst());
        $this->assertSame([], $health->failing());
        $this->artisan('central:health')->assertExitCode(0)->expectsOutputToContain('HEALTHY');
    }

    public function test_the_scheduler_backups_failed_jobs_and_disk_escalate_from_warning_to_critical(): void
    {
        $this->healthy();

        Cache::forget(OpsHealth::HEARTBEAT_KEY);
        $this->assertSame(OpsStatus::Critical, $this->check('scheduler')->status);
        $this->assertStringContainsString('No heartbeat', $this->check('scheduler')->finding);
        Cache::put(OpsHealth::HEARTBEAT_KEY, time() - 6 * 60);
        $this->assertSame(OpsStatus::Warning, $this->check('scheduler')->status);
        Cache::put(OpsHealth::HEARTBEAT_KEY, time() - 20 * 60);
        $this->assertSame(OpsStatus::Critical, $this->check('scheduler')->status);
        OpsHealth::beat();

        BackupRun::query()->delete();
        $this->assertSame(OpsStatus::Critical, $this->check('backup')->status);
        $run = BackupRun::query()->create(['started_at' => now()->subHours(30), 'status' => BackupRun::VERIFIED, 'disk' => 's3', 'offsite' => true]);
        $this->assertSame(OpsStatus::Warning, $this->check('backup')->status);
        $run->forceFill(['started_at' => now()->subHours(60)])->save();
        $this->assertSame(OpsStatus::Critical, $this->check('backup')->status);
        BackupRun::query()->create(['started_at' => now(), 'status' => BackupRun::VERIFIED, 'disk' => 'local', 'offsite' => false]);
        $this->assertSame(OpsStatus::Critical, $this->check('backup')->status, 'a local copy does not count');

        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $this->assertSame(OpsStatus::Warning, $this->check('failed_jobs')->status);
        for ($i = 2; $i <= 10; $i++) {
            DB::table('failed_jobs')->insert(['uuid' => "u{$i}", 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        }
        $this->assertSame(OpsStatus::Critical, $this->check('failed_jobs')->status);
        $this->artisan('central:health')->assertExitCode(2);

        config(['ops.health.disk_critical_percent' => 101, 'ops.health.disk_warning_percent' => 101]);
        $this->assertSame(OpsStatus::Critical, $this->check('disk')->status);
        config(['ops.health.disk_critical_percent' => 0, 'ops.health.disk_warning_percent' => 101]);
        $this->assertSame(OpsStatus::Warning, $this->check('disk')->status);
    }

    public function test_the_alert_mails_every_active_administrator_once_per_incident_and_re_arms_on_recovery(): void
    {
        $this->healthy();
        $owner = $this->actingAsAdmin();
        User::factory()->create(['access_type' => 'administrator', 'is_active' => false, 'email' => 'gone@example.test']);
        User::factory()->create(['access_type' => 'operator', 'is_active' => true, 'email' => 'staff@example.test']);
        config(['ops.alert.extra_email' => 'ops@example.test']);

        $this->assertFalse(app(OpsAlerter::class)->sweep(), 'nothing critical, nothing sent');
        Mail::assertNothingSent();

        Cache::forget(OpsHealth::HEARTBEAT_KEY);
        $this->artisan('central:health', ['--alert' => true])->assertExitCode(2)->expectsOutputToContain('Alert mailed');
        Mail::assertSentCount(1);
        Mail::assertSent(SystemAlertMessage::class, function (SystemAlertMessage $mail) use ($owner): bool {
            $this->assertTrue($mail->hasTo($owner->email) && $mail->hasTo('ops@example.test'));
            $this->assertFalse($mail->hasTo('gone@example.test') || $mail->hasTo('staff@example.test'));
            $text = $mail->render();
            $this->assertStringContainsString('CRITICAL', $text);
            $this->assertStringContainsString('No heartbeat', $text);
            $this->assertStringContainsString('central:health', $text);

            return true;
        });

        $this->assertFalse(app(OpsAlerter::class)->sweep(), 'the same incident is not mailed again');
        Mail::assertSentCount(1);

        OpsHealth::beat();
        $this->assertFalse(app(OpsAlerter::class)->sweep(), 'recovered: the throttle is dropped');
        Cache::forget(OpsHealth::HEARTBEAT_KEY);
        $this->assertTrue(app(OpsAlerter::class)->sweep(), 'a new incident mails again');
        Mail::assertSentCount(2);
    }

    public function test_the_heartbeat_the_sweep_and_the_backup_are_scheduled_once_at_a_time_on_one_server(): void
    {
        $events = collect(app(Schedule::class)->events());
        $heartbeat = $events->first(fn ($e) => $e->description === 'ops-heartbeat');
        $this->assertNotNull($heartbeat);
        $this->assertSame('* * * * *', $heartbeat->expression);
        $sweep = $events->first(fn ($e) => str_contains((string) $e->command, 'central:health'));
        $this->assertSame('0 * * * *', $sweep->expression);
        $this->assertTrue($sweep->withoutOverlapping && $sweep->onOneServer);
        $this->assertStringContainsString('--alert', (string) $sweep->command);
    }
}
