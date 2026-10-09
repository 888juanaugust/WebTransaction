<?php

declare(strict_types=1);

namespace App\Client\Domain\Ops\Health;

use App\Client\Models\BackupRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The seven things that fail quietly on one VPS: the database, Redis, the
 * queue, failed jobs, the scheduler (a heartbeat the schedule writes every
 * minute), the backups and the disk. Every check catches its own exception
 * as critical: a check that cannot measure is a finding, not a crash.
 * Thresholds in config/ops.php (app/Client/config/ops.php).
 */
class OpsHealth
{
    public const HEARTBEAT_KEY = 'ops:scheduler-heartbeat';

    /** @return list<OpsCheck> */
    public function checks(): array
    {
        return [$this->database(), $this->redis(), $this->queue(), $this->failedJobs(), $this->scheduler(), $this->backup(), $this->disk()];
    }

    public function worst(): OpsStatus
    {
        $worst = OpsStatus::Healthy;
        foreach ($this->checks() as $check) {
            if ($check->status->worseThan($worst)) {
                $worst = $check->status;
            }
        }

        return $worst;
    }

    /** @return list<OpsCheck> */
    public function failing(): array
    {
        return array_values(array_filter($this->checks(), fn (OpsCheck $c) => $c->status !== OpsStatus::Healthy));
    }

    public static function beat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, time());
    }

    private function database(): OpsCheck
    {
        $title = 'PostgreSQL';
        try {
            $start = hrtime(true);
            DB::select('select 1');
            $ms = (hrtime(true) - $start) / 1_000_000;

            return $ms > (int) config('ops.health.database_warning_ms')
                ? OpsCheck::warning('database', $title, __('Answers, but an empty query takes :ms ms', ['ms' => (int) round($ms)]))
                : OpsCheck::healthy('database', $title, sprintf('%0.1f ms', $ms));
        } catch (Throwable $e) {
            return OpsCheck::critical('database', $title, __('Unreachable: :error', ['error' => $e->getMessage()]));
        }
    }

    private function redis(): OpsCheck
    {
        $title = __('Cache and queue (Redis)');
        try {
            $token = 'probe-'.mt_rand();
            Cache::put('ops:redis-probe', $token, 10);

            return Cache::get('ops:redis-probe') === $token
                ? OpsCheck::healthy('redis', $title, __('Written and read back'))
                : OpsCheck::critical('redis', $title, __('A write did not come back'));
        } catch (Throwable $e) {
            return OpsCheck::critical('redis', $title, __('Unreachable: :error', ['error' => $e->getMessage()]));
        }
    }

    private function queue(): OpsCheck
    {
        $title = __('Job queue');
        try {
            $waiting = (int) Queue::size();
            $finding = __(':count job(s) waiting', ['count' => $waiting]);

            return match (true) {
                $waiting > (int) config('ops.health.queue_critical') => OpsCheck::critical('queue', $title, $finding.' '.__('(the worker is dead or stuck)')),
                $waiting > (int) config('ops.health.queue_warning') => OpsCheck::warning('queue', $title, $finding),
                default => OpsCheck::healthy('queue', $title, $finding),
            };
        } catch (Throwable $e) {
            return OpsCheck::critical('queue', $title, __('Not measurable: :error', ['error' => $e->getMessage()]));
        }
    }

    private function failedJobs(): OpsCheck
    {
        $title = __('Failed jobs');
        try {
            $failed = (int) DB::table('failed_jobs')->count();
            $finding = $failed === 0 ? __('None') : __(':count failed job(s); php artisan queue:failed', ['count' => $failed]);

            return match (true) {
                $failed >= (int) config('ops.health.failed_jobs_critical') => OpsCheck::critical('failed_jobs', $title, $finding),
                $failed >= (int) config('ops.health.failed_jobs_warning') => OpsCheck::warning('failed_jobs', $title, $finding),
                default => OpsCheck::healthy('failed_jobs', $title, $finding),
            };
        } catch (Throwable $e) {
            return OpsCheck::critical('failed_jobs', $title, __('Not measurable: :error', ['error' => $e->getMessage()]));
        }
    }

    private function scheduler(): OpsCheck
    {
        $title = __('Scheduler');
        try {
            $last = Cache::get(self::HEARTBEAT_KEY);
            if ($last === null) {
                return OpsCheck::critical('scheduler', $title, __('No heartbeat: the cron entry for schedule:run is not running, so nothing nightly runs either'));
            }
            $minutes = (int) floor((time() - (int) $last) / 60);
            $finding = __('Last heartbeat :minutes minute(s) ago', ['minutes' => $minutes]);

            return match (true) {
                $minutes >= (int) config('ops.health.heartbeat_critical_minutes') => OpsCheck::critical('scheduler', $title, $finding),
                $minutes >= (int) config('ops.health.heartbeat_warning_minutes') => OpsCheck::warning('scheduler', $title, $finding),
                default => OpsCheck::healthy('scheduler', $title, $finding),
            };
        } catch (Throwable $e) {
            return OpsCheck::critical('scheduler', $title, __('Not measurable: :error', ['error' => $e->getMessage()]));
        }
    }

    private function backup(): OpsCheck
    {
        $title = __('Backups');
        try {
            $last = BackupRun::query()->verified()->where('offsite', true)->latest('started_at')->first();
            if ($last === null) {
                return OpsCheck::critical('backup', $title, __('No verified backup off this server yet'));
            }
            $hours = (int) floor($last->started_at->diffInHours(now()));
            $finding = __('Last one :hours hour(s) ago', ['hours' => $hours]);

            return match (true) {
                $hours > (int) config('ops.health.backup_critical_hours') => OpsCheck::critical('backup', $title, $finding.' '.__('(two nights missed)')),
                $hours > (int) config('ops.health.backup_warning_hours') => OpsCheck::warning('backup', $title, $finding),
                default => OpsCheck::healthy('backup', $title, $finding),
            };
        } catch (Throwable $e) {
            return OpsCheck::critical('backup', $title, __('Not measurable: :error', ['error' => $e->getMessage()]));
        }
    }

    private function disk(): OpsCheck
    {
        $title = __('Disk space');
        try {
            $path = storage_path();
            $free = disk_free_space($path);
            $total = disk_total_space($path);
            if ($free === false || $total === false || $total <= 0) {
                return OpsCheck::critical('disk', $title, __('Not measurable'));
            }
            $percent = (int) round($free / $total * 100);
            $gb = round($free / 1_073_741_824, 1);
            $finding = __(':percent % free (:gb GB)', ['percent' => $percent, 'gb' => $gb]);

            return match (true) {
                $percent < (int) config('ops.health.disk_critical_percent') => OpsCheck::critical('disk', $title, $finding.' '.__('(tonight\'s dump may fail)')),
                $percent < (int) config('ops.health.disk_warning_percent') => OpsCheck::warning('disk', $title, $finding),
                default => OpsCheck::healthy('disk', $title, $finding),
            };
        } catch (Throwable $e) {
            return OpsCheck::critical('disk', $title, __('Not measurable: :error', ['error' => $e->getMessage()]));
        }
    }
}
