<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\BackupCommand;
use App\Client\Console\BackupKeyCommand;
use App\Client\Console\HealthCommand;
use App\Client\Console\IntegrityCommand;
use App\Client\Console\LaunchCheckCommand;
use App\Client\Console\RestoreCommand;
use App\Client\Domain\Ops\Health\OpsHealth;
use App\Client\Models\BackupRun;
use App\Client\Models\LaunchAttestation;
use App\Client\Screens\CentralScreen;
use App\Modules\BaseModule;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Operations: encrypted, verified backups and their restore; the health
 * checks and the critical alert; the ledger integrity sweep; launch
 * readiness. Always on.
 */
final class OpsModule extends BaseModule
{
    public static function key(): string
    {
        return 'central-ops';
    }

    public static function menuKeys(): array
    {
        return [CentralScreen::LaunchReadiness];
    }

    public static function morphMap(): array
    {
        return ['backup_run' => BackupRun::class, 'launch_attestation' => LaunchAttestation::class];
    }

    public static function commands(): array
    {
        return [BackupCommand::class, BackupKeyCommand::class, RestoreCommand::class, HealthCommand::class, IntegrityCommand::class, LaunchCheckCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        // The heartbeat the scheduler check reads: a minute without it means cron is not running.
        $schedule->call(fn () => OpsHealth::beat())->everyMinute()->name('ops-heartbeat')->withoutOverlapping()->onOneServer();
        $schedule->command('central:health --alert')->hourly()->withoutOverlapping()->onOneServer();
        // The sweep before the backup, so the night's backup holds books already checked.
        $schedule->command('central:integrity --notify')->dailyAt('01:30')->withoutOverlapping(60)->onOneServer();
        // Not runInBackground(): the exit code of a failed backup must reach the scheduler.
        $schedule->command('central:backup')->dailyAt('02:15')->withoutOverlapping(60)->onOneServer();
    }
}
