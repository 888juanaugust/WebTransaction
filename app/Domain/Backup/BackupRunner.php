<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\BackupRun;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Taking a backup: dump, encrypt, store, **read it back**, record, prune.
 *
 * The step most implementations leave out is the fourth. Writing a file and
 * reporting success tests that the disk accepted bytes, which is not the
 * question anybody actually has. So every run downloads its own artefact and
 * decrypts it before calling itself done, and the only success state on
 * `backup_runs` is `verified`.
 *
 * That catches, at creation rather than at three in the morning: a key that
 * changed since the last run, a truncated upload, a full disk, storage that
 * silently dropped the write, a corrupted transfer.
 *
 * Pruning happens **after** verification and never before. Deleting the old
 * copy first would mean a bad night costs the good one too, which is the
 * failure that turns an outage into a closure.
 */
class BackupRunner
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly BackupCipher $cipher,
        private readonly FileArchiver $files,
    ) {}

    public function run(?string $catatan = null): BackupRun
    {
        $startedAt = Carbon::now();
        $disk = (string) config('backup.disk');

        $run = BackupRun::create([
            'started_at' => $startedAt,
            'status' => BackupRun::STATUS_RUNNING,
            'disk' => $disk,
            'offsite' => $this->isOffsite($disk),
            'catatan' => $catatan,
        ]);

        $scratch = [];

        try {
            $storage = Storage::disk($disk);
            $stamp = $startedAt->format('Ymd-His');
            $prefix = rtrim((string) config('backup.path'), '/')
                .'/'.$startedAt->format('Y/m').'/'.$stamp;

            // --- database ---------------------------------------------------
            $dump = $this->scratchPath("{$stamp}-db.sql");
            $scratch[] = $dump;

            $this->dumper->dumpTo($dump);

            $databasePath = "{$prefix}-db.sql.enc";
            $databaseBytes = $this->store($storage, $dump, $databasePath);

            // --- kept-forever files ------------------------------------------
            $filesPath = null;
            $filesBytes = 0;

            if (config('backup.include_files') && $this->files->hasAnything()) {
                $tar = $this->scratchPath("{$stamp}-files.tar");
                $scratch[] = $tar;

                $this->files->archiveTo($tar);

                $filesPath = "{$prefix}-files.tar.enc";
                $filesBytes = $this->store($storage, $tar, $filesPath);
            }

            // --- read back what we just wrote --------------------------------
            $verified = $this->verify($storage, $databasePath);

            if ($filesPath !== null) {
                $verified += $this->verify($storage, $filesPath);
            }

            $run->forceFill([
                'status' => BackupRun::STATUS_VERIFIED,
                'finished_at' => Carbon::now(),
                'duration_seconds' => (int) Carbon::now()->diffInSeconds($startedAt, absolute: true),
                'database_path' => $databasePath,
                'files_path' => $filesPath,
                'database_bytes' => $databaseBytes,
                'files_bytes' => $filesBytes,
                'verified_bytes' => $verified,
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => BackupRun::STATUS_FAILED,
                'finished_at' => Carbon::now(),
                'duration_seconds' => (int) Carbon::now()->diffInSeconds($startedAt, absolute: true),
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

    /**
     * Decrypt an artefact and hand back the plaintext path.
     *
     * Used by the restore command and by anybody who needs to look inside one.
     * The caller owns the file it is given and should delete it — it is the
     * database in the clear.
     */
    public function decryptTo(string $remotePath, string $localPath, ?string $disk = null): int
    {
        $storage = Storage::disk($disk ?? (string) config('backup.disk'));

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

    /**
     * Delete runs older than the retention window.
     *
     * Only ever called after a fresh verified run, so the newest good copy is
     * never the one being deleted. One backup per calendar month is kept for a
     * year on top of the daily window, because the mistakes that need an old
     * backup — a bad import, a wrong price published — are usually noticed
     * weeks after they happened, not the next morning.
     *
     * @return int runs pruned
     */
    public function prune(): int
    {
        $keepDaily = (int) config('backup.keep_daily');
        $keepMonthly = (int) config('backup.keep_monthly');

        $cutoff = Carbon::now()->subDays(max(1, $keepDaily));

        $candidates = BackupRun::query()
            ->verified()
            ->where('started_at', '<', $cutoff)
            ->orderByDesc('started_at')
            ->get();

        $keptMonths = [];
        $pruned = 0;

        foreach ($candidates as $run) {
            $month = $run->started_at->format('Y-m');

            if (! isset($keptMonths[$month]) && count($keptMonths) < $keepMonthly) {
                $keptMonths[$month] = true;

                continue;
            }

            $this->deleteArtefacts($run);
            $run->delete();
            $pruned++;
        }

        /*
         * Failed runs are kept far longer than they are useful and then
         * dropped, because a year of nightly failures is a lot of rows and the
         * newest few are the only ones anybody reads.
         */
        $pruned += BackupRun::query()
            ->where('status', BackupRun::STATUS_FAILED)
            ->where('started_at', '<', Carbon::now()->subDays(90))
            ->delete();

        return $pruned;
    }

    /**
     * Is this destination somewhere other than the machine being backed up?
     *
     * A local disk is not, and saying otherwise in config does not make it so
     * — the flag exists only for a local path that is itself a mount of remote
     * storage. Getting this wrong makes the dashboard confidently wrong, which
     * is worse than it being blank.
     */
    private function isOffsite(string $disk): bool
    {
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        if ($driver !== 'local') {
            return true;
        }

        return (bool) config('backup.local_is_offsite');
    }

    /** Encrypt a local file onto the backup disk. Returns bytes stored. */
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

    /**
     * Read the artefact back off the disk and decrypt it, keeping nothing.
     *
     * Nothing is written anywhere: the point is to prove the bytes are
     * readable and authentic, and a verification that needs room for a second
     * copy of the database is a verification that stops running once the
     * database is large.
     */
    private function verify(Filesystem $storage, string $remotePath): int
    {
        $in = $storage->readStream($remotePath);

        if ($in === null || $in === false) {
            throw new RuntimeException(
                "Wrote {$remotePath} but cannot read it back. The destination accepted it and lost it."
            );
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

    private function scratchPath(string $name): string
    {
        $dir = storage_path('app/backup-scratch');

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.'/'.$name;
    }
}
