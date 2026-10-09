<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Backup;

use App\Client\Models\BackupRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * A backup: dump, encrypt, store, read back, record, prune. The read-back
 * is the step that matters: writing a file proves the disk took bytes,
 * which is not the question. The only success state is `verified`, meaning
 * the artefact was downloaded again and decrypted. Pruning runs after a
 * verified run and never before it, so a bad night never costs the good copy.
 */
class BackupRunner
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly BackupCipher $cipher,
        private readonly FileArchiver $files,
    ) {}

    public function run(?string $note = null): BackupRun
    {
        $startedAt = Carbon::now();
        $disk = (string) config('ops.backup.disk');
        $run = BackupRun::query()->create([
            'started_at' => $startedAt, 'status' => BackupRun::RUNNING, 'disk' => $disk, 'offsite' => self::isOffsite($disk), 'note' => $note,
        ]);
        $scratch = [];
        try {
            $storage = Storage::disk($disk);
            $stamp = $startedAt->format('Ymd-His');
            $prefix = rtrim((string) config('ops.backup.path'), '/').'/'.$startedAt->format('Y/m').'/'.$stamp;

            $dump = $this->scratchPath("{$stamp}-db.sql");
            $scratch[] = $dump;
            $this->dumper->dumpTo($dump);
            $databasePath = "{$prefix}-db.sql.enc";
            $databaseBytes = $this->store($storage, $dump, $databasePath);

            $filesPath = null;
            $filesBytes = 0;
            if (config('ops.backup.include_files') && $this->files->hasAnything()) {
                $tar = $this->scratchPath("{$stamp}-files.tar");
                $scratch[] = $tar;
                $this->files->archiveTo($tar);
                $filesPath = "{$prefix}-files.tar.enc";
                $filesBytes = $this->store($storage, $tar, $filesPath);
            }

            $verified = $this->verify($storage, $databasePath);
            if ($filesPath !== null) {
                $verified += $this->verify($storage, $filesPath);
            }

            $run->forceFill([
                'status' => BackupRun::VERIFIED, 'finished_at' => Carbon::now(),
                'duration_seconds' => (int) $startedAt->diffInSeconds(Carbon::now(), absolute: true),
                'database_path' => $databasePath, 'files_path' => $filesPath,
                'database_bytes' => $databaseBytes, 'files_bytes' => $filesBytes, 'verified_bytes' => $verified,
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => BackupRun::FAILED, 'finished_at' => Carbon::now(),
                'duration_seconds' => (int) $startedAt->diffInSeconds(Carbon::now(), absolute: true),
                'error' => $e->getMessage(),
            ])->save();
            throw $e;
        } finally {
            foreach ($scratch as $path) {
                @unlink($path);
            }
        }

        return $run->refresh();
    }

    /** Decrypts an artefact to a local file the caller owns and must delete: it is the database in the clear. */
    public function decryptTo(string $remotePath, string $localPath, ?string $disk = null): int
    {
        $storage = Storage::disk($disk ?? (string) config('ops.backup.disk'));
        $in = $storage->readStream($remotePath);
        if ($in === null || $in === false) {
            throw new RuntimeException("Backup artefact not found: {$remotePath}");
        }
        $out = fopen($localPath, 'wb');
        if ($out === false) {
            throw new RuntimeException("Could not open {$localPath} to write the plaintext.");
        }
        try {
            return $this->cipher->decrypt($in, $out);
        } finally {
            fclose($out);
            @fclose($in);
        }
    }

    /** Keeps the daily window, then the first verified run of each month for the monthly window; never the newest copy. @return int runs pruned */
    public function prune(): int
    {
        $keepDaily = (int) config('ops.backup.keep_daily');
        $keepMonthly = (int) config('ops.backup.keep_monthly');
        $cutoff = Carbon::now()->subDays(max(1, $keepDaily));
        $newest = BackupRun::query()->verified()->latest('started_at')->first();
        $candidates = BackupRun::query()->verified()->where('started_at', '<', $cutoff)->orderByDesc('started_at')->get();
        $keptMonths = [];
        $pruned = 0;
        foreach ($candidates as $run) {
            if ($newest !== null && $run->is($newest)) {
                continue;
            }
            $month = $run->started_at->format('Y-m');
            if (! isset($keptMonths[$month]) && count($keptMonths) < $keepMonthly) {
                $keptMonths[$month] = true;

                continue;
            }
            $this->deleteArtefacts($run);
            $run->delete();
            $pruned++;
        }
        $pruned += BackupRun::query()->where('status', BackupRun::FAILED)->where('started_at', '<', Carbon::now()->subDays(90))->delete();

        return $pruned;
    }

    /** A local disk is not off the box, whatever the config says, unless it is a mount of remote storage. */
    public static function isOffsite(string $disk): bool
    {
        if ((string) config("filesystems.disks.{$disk}.driver", 'local') !== 'local') {
            return true;
        }

        return (bool) config('ops.backup.local_is_offsite');
    }

    private function store(Filesystem $storage, string $localPath, string $remotePath): int
    {
        $in = fopen($localPath, 'rb');
        if ($in === false) {
            throw new RuntimeException("Could not read {$localPath}.");
        }
        $encrypted = $localPath.'.enc';
        $out = fopen($encrypted, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException("Could not open {$encrypted}.");
        }
        try {
            $this->cipher->encrypt($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }
        $handle = fopen($encrypted, 'rb');
        try {
            $storage->writeStream($remotePath, $handle);
        } finally {
            @fclose($handle);
        }
        $bytes = (int) @filesize($encrypted);
        @unlink($encrypted);

        return $bytes;
    }

    /** Reads the artefact back and decrypts it, keeping nothing: proof the bytes are readable and authentic. */
    private function verify(Filesystem $storage, string $remotePath): int
    {
        $in = $storage->readStream($remotePath);
        if ($in === null || $in === false) {
            throw new RuntimeException("Wrote {$remotePath} but cannot read it back: the destination accepted it and lost it.");
        }
        try {
            return $this->cipher->decrypt($in, null);
        } finally {
            @fclose($in);
        }
    }

    private function deleteArtefacts(BackupRun $run): void
    {
        $storage = Storage::disk($run->disk);
        foreach ([$run->database_path, $run->files_path] as $path) {
            if ($path !== null && $storage->exists($path)) {
                $storage->delete($path);
            }
        }
    }

    public static function scratchPath(string $name): string
    {
        $dir = storage_path('app/backup-scratch');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.'/'.$name;
    }
}
