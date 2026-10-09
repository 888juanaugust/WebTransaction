<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Ops\Backup\BackupHealth;
use App\Client\Domain\Ops\Backup\BackupRunner;
use Illuminate\Console\Command;
use Throwable;

/** Takes a backup: dump, encrypt, store off the box, read back. Exits non-zero on failure so whatever watches it finds out. */
class BackupCommand extends Command
{
    protected $signature = 'central:backup {--note= : A note recorded against this run} {--no-prune : Keep old backups past the retention window}';

    protected $description = 'Dump, encrypt, store and verify a backup of the database and the kept files';

    public function handle(BackupRunner $runner, BackupHealth $health): int
    {
        try {
            $run = $runner->run($this->option('note') ?: null);
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            $this->line('The failure is recorded in backup_runs and shows on the Operations screen.');

            return self::FAILURE;
        }

        $this->info(sprintf('Backup verified: %s of database%s, read back and decrypted in %ds.', self::bytes($run->database_bytes), $run->files_bytes > 0 ? ' plus '.self::bytes($run->files_bytes).' of files' : '', (int) $run->duration_seconds));
        $this->line('  '.$run->database_path);
        if (! $run->offsite) {
            $this->warn('  This copy sits on the same machine as the database it came from: it protects against a dropped table and nothing else. Set BACKUP_DISK to a bucket (docs/DEPLOY.md).');
        }
        if (! $this->option('no-prune')) {
            $pruned = $runner->prune();
            if ($pruned > 0) {
                $this->line("  Pruned {$pruned} run(s) past the retention window.");
            }
        }
        $this->line('  Status: '.$health->message());

        return self::SUCCESS;
    }

    public static function bytes(int $bytes): string
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
