<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupRunner;
use App\Domain\Backup\DatabaseDumper;
use App\Domain\Backup\FileArchiver;
use App\Models\BackupRun;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restore from a backup.
 *
 * This exists so that "test the restore before launch" is one command rather
 * than a page of shell somebody has to get right while the business is down.
 * A restore procedure that has never been run is not a procedure.
 *
 * It is destructive by definition — the dump is taken with `--clean
 * --if-exists`, so restoring drops what is there and puts the backup in its
 * place. Hence the confirmation, and hence `--into`, which is what anybody
 * *practising* a restore should use: it restores into a different database and
 * touches nothing real.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
        {--run= : Which backup_runs row. Defaults to the newest verified one}
        {--into= : Restore into this database instead of the live one. Use this to practise}
        {--files : Also unpack the archived files over storage/app/private}
        {--force : Skip the confirmation. For scripts, not for people}';

    protected $description = 'Restore the database from an encrypted backup';

    public function handle(BackupRunner $runner, FileArchiver $archiver): int
    {
        $run = $this->resolveRun();

        if ($run === null) {
            $this->error('No verified backup to restore from.');

            return self::FAILURE;
        }

        $dumper = DatabaseDumper::fromConfig();
        $target = $this->option('into') ?: $dumper->databaseName();
        $practice = $this->option('into') !== null;

        $this->line("Backup:   {$run->database_path}");
        $this->line('Taken:    '.$run->started_at->toDateTimeString().' ('.$run->started_at->diffForHumans().')');
        $this->line("Restores into: {$target}");

        if (! $practice) {
            $this->newLine();
            $this->warn('This REPLACES the live database. Everything in it now will be gone.');
            $this->warn('To practise instead, pass --into=a_scratch_database.');
        }

        if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->line('Nothing done.');

            return self::SUCCESS;
        }

        $scratch = storage_path('app/backup-scratch');

        if (! is_dir($scratch)) {
            mkdir($scratch, 0700, true);
        }

        $plain = $scratch.'/restore-'.$run->id.'.sql';

        try {
            $this->line('Decrypting…');
            $bytes = $runner->decryptTo($run->database_path, $plain, $run->disk);
            $this->line('  '.number_format($bytes).' bytes recovered and authenticated.');

            $this->line('Restoring…');
            $dumper->restoreFrom($plain, $this->option('into') ?: null);

            if ($this->option('files') && $run->files_path !== null) {
                $tar = $scratch.'/restore-'.$run->id.'.tar';

                $this->line('Unpacking files…');
                $runner->decryptTo($run->files_path, $tar, $run->disk);
                $archiver->extractTo($tar, (string) config('backup.files_root'));

                @unlink($tar);
            }
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // The plaintext dump is the entire database in the clear. It does
            // not get to outlive the command that made it.
            @unlink($plain);
        }

        $this->newLine();
        $this->info("Restored into {$target}.");

        if ($practice) {
            $this->line('That was a practice run — the live database was not touched.');
        } else {
            $this->line('Check the figures before letting anybody back in:');
            $this->line('  php artisan tinker --execute="echo App\\\\Models\\\\Order::count();"');
        }

        return self::SUCCESS;
    }

    private function resolveRun(): ?BackupRun
    {
        if ($id = $this->option('run')) {
            return BackupRun::query()->find($id);
        }

        return BackupRun::query()->verified()->latest('started_at')->first();
    }
}
