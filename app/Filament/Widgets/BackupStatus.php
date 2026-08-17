<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Backup\BackupHealth;
use Filament\Widgets\Widget;

/**
 * Says something only when the backups need attention.
 *
 * Sits at the top of the dashboard when it appears, above the work queues,
 * because a day of unprocessed orders is a bad day and a month of missing
 * backups is a different category of problem.
 *
 * **Silent when healthy.** A green tick that is always there stops being read
 * within a week, and then it is decoration on the one morning it turns red.
 * The owner can still check deliberately from the command line; this widget is
 * for the case where nobody was checking.
 */
class BackupStatus extends Widget
{
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.backup-status';

    public static function canView(): bool
    {
        /*
         * Whoever can see the audit log — the owner. Backups are an
         * infrastructure worry rather than a job anybody else can act on, and
         * a warning shown to somebody who cannot fix it is noise that trains
         * them to ignore warnings.
         */
        if (! (auth()->user()?->role()->canViewAuditLog() ?? false)) {
            return false;
        }

        return ! app(BackupHealth::class)->isHealthy();
    }

    public function getHealth(): BackupHealth
    {
        return app(BackupHealth::class);
    }
}
