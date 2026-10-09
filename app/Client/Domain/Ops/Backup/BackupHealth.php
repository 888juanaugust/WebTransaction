<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Backup;

use App\Client\Models\BackupRun;
use Illuminate\Support\Carbon;

/**
 * Whether the backups are happening. They do not fail with a bang: cron
 * stops, a credential expires, a key is rotated, and nothing says so unless
 * something looks. States, in the order they matter: never, failing (the
 * last attempt failed), stale (the last good one is old), local (good, but
 * on the same machine), ok.
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
        $attempt = $this->lastAttempt();
        if ($attempt !== null && $attempt->status === BackupRun::FAILED && $attempt->started_at->greaterThan($verified->started_at)) {
            return self::FAILING;
        }
        if ($verified->started_at->diffInHours(Carbon::now()) > (int) config('ops.backup.stale_after_hours')) {
            return self::STALE;
        }

        return $verified->offsite ? self::OK : self::LOCAL;
    }

    public function isHealthy(): bool
    {
        return $this->state() === self::OK;
    }

    /** What to tell somebody, through the translator. */
    public function message(): string
    {
        $verified = $this->lastVerified();
        $attempt = $this->lastAttempt();
        $when = $verified?->started_at?->diffForHumans() ?? '—';

        return match ($this->state()) {
            self::NEVER => __('No backup has ever been verified. Run php artisan central:backup and read docs/DEPLOY.md.'),
            self::FAILING => __('The last backup FAILED :when. Last good copy: :good. :error', ['when' => $attempt?->started_at?->diffForHumans() ?? '', 'good' => $when, 'error' => trim((string) $attempt?->error)]),
            self::STALE => __('The last verified backup is from :when, older than the :hours-hour limit. Check the scheduler.', ['when' => $when, 'hours' => (int) config('ops.backup.stale_after_hours')]),
            self::LOCAL => __('Backup verified :when, but still on this machine: if the server dies, the backup dies with it.', ['when' => $when]),
            default => __('Backup verified :when, stored off this machine.', ['when' => $when]),
        };
    }

    public function color(): string
    {
        return match ($this->state()) {
            self::OK => 'success',
            self::LOCAL, self::STALE => 'warning',
            default => 'danger',
        };
    }
}
