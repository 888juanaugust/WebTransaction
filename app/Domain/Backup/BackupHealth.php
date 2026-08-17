<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\BackupRun;
use Illuminate\Support\Carbon;

/**
 * Whether the backups are actually happening.
 *
 * This is the most valuable part of a backup system and the part usually
 * missing. Backups do not fail with a bang; cron stops firing, a credential
 * expires, a disk fills, somebody rotates the key — and nothing says so,
 * because nothing was looking. The gap between "our backups broke" and "we
 * found out" is where businesses are lost, and this class exists to make that
 * gap a day.
 *
 * Four states, in the order they matter:
 *
 *  - **never**: no verified run has ever happened.
 *  - **failing**: the last attempt failed. Different from stale, and needs a
 *    different response — something is broken now, rather than nothing having
 *    run.
 *  - **stale**: the last verified run is older than the window.
 *  - **local**: recent and verified, but it never left the machine. Protects
 *    against a dropped table and nothing against the disk dying.
 *
 * Only when none of those hold is it `ok`.
 */
class BackupHealth
{
    public const OK = 'ok';

    public const NEVER = 'never';

    public const FAILING = 'failing';

    public const STALE = 'stale';

    public const LOCAL = 'local';

    public function lastVerified(): ?BackupRun
    {
        return BackupRun::query()->verified()->latest('started_at')->first();
    }

    public function lastAttempt(): ?BackupRun
    {
        return BackupRun::query()->latest('started_at')->first();
    }

    public function state(): string
    {
        $verified = $this->lastVerified();

        if ($verified === null) {
            return self::NEVER;
        }

        /*
         * A failing run outranks a stale one. If last night broke, saying
         * "stale" sends somebody to look at the schedule when the schedule is
         * fine and the destination is not.
         */
        $attempt = $this->lastAttempt();

        if ($attempt !== null
            && $attempt->status === BackupRun::STATUS_FAILED
            && $attempt->started_at->greaterThan($verified->started_at)) {
            return self::FAILING;
        }

        $hours = (int) config('backup.stale_after_hours');

        if ($verified->started_at->diffInHours(Carbon::now()) > $hours) {
            return self::STALE;
        }

        return $verified->offsite ? self::OK : self::LOCAL;
    }

    public function isHealthy(): bool
    {
        return $this->state() === self::OK;
    }

    /** What to tell somebody, in the language of the panel. */
    public function message(): string
    {
        $verified = $this->lastVerified();
        $attempt = $this->lastAttempt();

        return match ($this->state()) {
            self::NEVER => 'Belum pernah ada backup yang berhasil diverifikasi. '
                .'Jalankan `php artisan backup:run` dan baca docs/BACKUP.md.',

            self::FAILING => 'Backup terakhir GAGAL'
                .($attempt?->started_at ? ' ('.$attempt->started_at->diffForHumans().')' : '').'. '
                .'Salinan baik terakhir: '.($verified?->started_at?->diffForHumans() ?? '—').'. '
                .trim((string) $attempt?->error),

            self::STALE => 'Backup terverifikasi terakhir '
                .($verified?->started_at?->diffForHumans() ?? '—')
                .' — lebih lama dari batas '.config('backup.stale_after_hours').' jam. '
                .'Periksa penjadwalnya.',

            self::LOCAL => 'Backup terverifikasi '
                .($verified?->started_at?->diffForHumans() ?? '—')
                .', tapi masih di mesin yang sama. Kalau servernya mati, backup ikut mati.',

            default => 'Backup terverifikasi '
                .($verified?->started_at?->diffForHumans() ?? '—')
                .', tersimpan di luar mesin ini.',
        };
    }

    /** Filament colour for the state. */
    public function color(): string
    {
        return match ($this->state()) {
            self::OK => 'success',
            self::LOCAL => 'warning',
            self::STALE => 'warning',
            default => 'danger',
        };
    }
}
