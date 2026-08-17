<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupHealth;
use App\Domain\Backup\BackupRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Take a backup.
 *
 * Exits non-zero on failure so a scheduler, or whatever is watching it, finds
 * out. A backup command that swallows its own errors is worse than none: it
 * produces the reassurance without the backup.
 */
class BackupRunCommand extends Command
{
    protected $signature = 'backup:run
        {--catatan= : Note recorded against this run}
        {--no-prune : Keep old backups even if they are past the retention window}';

    protected $description = 'Dump, encrypt, store off-box, and verify a backup';

    public function handle(BackupRunner $runner, BackupHealth $health): int
    {
        try {
            $run = $runner->run($this->option('catatan') ?: null);
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $this->line('The failure is recorded in backup_runs and will show on the dashboard.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Backup verified: %s of database%s, read back and decrypted in %ds.',
            $this->bytes($run->database_bytes),
            $run->files_bytes > 0 ? ' plus '.$this->bytes($run->files_bytes).' of files' : '',
            (int) $run->duration_seconds,
        ));

        $this->line('  '.$run->database_path);

        if (! $run->offsite) {
            $this->newLine();
            $this->warn('  This copy is on the same machine as the database it came from.');
            $this->warn('  That protects you from a dropped table and from nothing else.');
            $this->warn('  Set BACKUP_DISK to somewhere off this box — see docs/BACKUP.md.');
        }

        if (! $this->option('no-prune')) {
            $pruned = $runner->prune();

            if ($pruned > 0) {
                $this->line("  Pruned {$pruned} run(s) past the retention window.");
            }
        }

        $this->newLine();
        $this->line('  Status: '.$health->message());

        return self::SUCCESS;
    }

    private function bytes(int $bytes): string
    {
        foreach (['B', 'KiB', 'MiB', 'GiB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GiB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes = (int) round($bytes / 1024);
        }

        return $bytes.' B';
    }
}
