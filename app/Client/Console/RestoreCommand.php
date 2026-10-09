<?php

declare(strict_types=1);

namespace App\Client\Console;

use App\Client\Domain\Ops\Backup\BackupRunner;
use App\Client\Domain\Ops\Backup\DatabaseDumper;
use App\Client\Domain\Ops\Backup\FileArchiver;
use App\Client\Models\BackupRun;
use Illuminate\Console\Command;
use Throwable;

/**
 * Restores from a backup, so the drill is one command and not a page of
 * shell. Destructive by nature (the dump drops what is there): the live
 * database only with --force; --into a scratch database is the practice run.
 */
class RestoreCommand extends Command
{
    protected $signature = 'central:restore
        {--run= : Which backup_runs row; the newest verified one by default}
        {--into= : Restore into this database instead of the live one (createdb it first); the practice run}
        {--files : Also unpack the archived files over storage/app/private}
        {--force : No confirmation; required for the live database}';

    protected $description = 'Restore the database from an encrypted backup';

    public function handle(BackupRunner $runner, FileArchiver $archiver): int
    {
        $run = $this->option('run') ? BackupRun::query()->find($this->option('run')) : BackupRun::query()->verified()->latest('started_at')->first();
        if ($run === null || ! $run->isVerified()) {
            $this->error('No verified backup to restore from.');

            return self::FAILURE;
        }
        $dumper = DatabaseDumper::fromConfig();
        $into = $this->option('into') ?: null;
        $target = $into ?? $dumper->databaseName();

        $this->line("Backup:        {$run->database_path}");
        $this->line('Taken:         '.$run->started_at->toDateTimeString().' ('.$run->started_at->diffForHumans().')');
        $this->line("Restores into: {$target}");
        if ($into === null) {
            $this->warn('This REPLACES the live database. Everything in it now is gone afterwards. To practise, pass --into=a_scratch_database.');
            if (! $this->option('force')) {
                $this->error('The live database is restored only with --force.');

                return self::FAILURE;
            }
        } elseif (! $this->option('force') && ! $this->confirm('Continue?', false)) {
            $this->line('Nothing done.');

            return self::SUCCESS;
        }

        $plain = BackupRunner::scratchPath('restore-'.$run->id.'.sql');
        try {
            $this->line('Decrypting…');
            $bytes = $runner->decryptTo($run->database_path, $plain, $run->disk);
            $this->line('  '.number_format($bytes).' bytes recovered and authenticated.');
            $this->line('Restoring…');
            $dumper->restoreFrom($plain, $into);
            if ($this->option('files') && $run->files_path !== null) {
                $tar = BackupRunner::scratchPath('restore-'.$run->id.'.tar');
                $this->line('Unpacking files…');
                $runner->decryptTo($run->files_path, $tar, $run->disk);
                $archiver->extractTo($tar, (string) config('ops.backup.files_root'));
                @unlink($tar);
            }
        } catch (Throwable $e) {
            $this->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($plain); // the database in the clear does not outlive the command
        }

        $this->info("Restored into {$target}.");
        $this->line($into !== null ? 'A practice run: the live database was not touched. Drop the scratch database when done.' : 'Clear the caches (php artisan optimize:clear) and check the figures before letting anybody back in.');

        return self::SUCCESS;
    }
}
