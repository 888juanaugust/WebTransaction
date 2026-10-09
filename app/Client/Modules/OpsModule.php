<?php

declare(strict_types=1);

namespace App\Client\Modules;

use App\Client\Console\BackupCommand;
use App\Client\Console\BackupKeyCommand;
use App\Client\Console\RestoreCommand;
use App\Client\Models\BackupRun;
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
        return [];
    }

    public static function morphMap(): array
    {
        return ['backup_run' => BackupRun::class];
    }

    public static function commands(): array
    {
        return [BackupCommand::class, BackupKeyCommand::class, RestoreCommand::class];
    }

    public static function schedule(Schedule $schedule): void
    {
        // Not runInBackground(): the exit code of a failed backup must reach the scheduler.
        $schedule->command('central:backup')->dailyAt('02:15')->withoutOverlapping(60)->onOneServer();
    }
}
