<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * What one box can honestly measure about itself.
 *
 * Seven checks, each the leading symptom of a failure this system has a
 * story for: Redis down is "staff cannot log in"; a dead scheduler is stock
 * fenced by orders nobody paid; a growing failed_jobs table is payments and
 * imports silently not happening; a stale backup is the one you find out
 * about on the day you need it.
 *
 * Every check catches its own exceptions — a health check that throws is a
 * health check that lies by omission, and the whole point is to keep
 * answering while things are on fire. External uptime (is the site
 * reachable from Jakarta) cannot be measured from here; DEPLOY.md says who
 * watches that.
 */
class OpsHealth
{
    /** Written every minute by the scheduler; its absence is the finding. */
    public const HEARTBEAT_KEY = 'ops:scheduler-heartbeat';

    /** @return list<OpsCheck> */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->redis(),
            $this->antrean(),
            $this->pekerjaanGagal(),
            $this->scheduler(),
            $this->cadangan(),
            $this->disk(),
        ];
    }

    public function worst(): OpsStatus
    {
        $worst = OpsStatus::Sehat;

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
        return array_values(array_filter(
            $this->checks(),
            fn (OpsCheck $c) => $c->status !== OpsStatus::Sehat,
        ));
    }

    private function database(): OpsCheck
    {
        try {
            $mulai = hrtime(true);
            DB::select('select 1');
            $ms = (hrtime(true) - $mulai) / 1_000_000;

            return $ms > 250
                ? OpsCheck::waspada('database', 'PostgreSQL', sprintf('Menjawab, tapi %0.0f ms untuk kueri kosong', $ms))
                : OpsCheck::sehat('database', 'PostgreSQL', sprintf('%0.1f ms', $ms));
        } catch (Throwable $e) {
            return OpsCheck::gawat('database', 'PostgreSQL', 'Tidak bisa dihubungi: '.$e->getMessage());
        }
    }

    private function redis(): OpsCheck
    {
        try {
            /*
             * Through the cache repository rather than the Redis facade, so
             * the check measures the path the application actually uses —
             * and degrades to the configured store in environments (tests)
             * where that store is not Redis at all.
             */
            // A string token, compared as a string: phpredis hands an
            // integer back as "1", and a strict comparison against int 1
            // reported a perfectly healthy Redis as broken — the live run
            // that caught it is why this comment exists.
            $token = 'probe-'.mt_rand();
            Cache::put('ops:redis-probe', $token, 10);

            return Cache::get('ops:redis-probe') === $token
                ? OpsCheck::sehat('redis', 'Cache/antrean (Redis)', 'Tulis-baca berhasil')
                : OpsCheck::gawat('redis', 'Cache/antrean (Redis)', 'Tulisan tidak terbaca kembali');
        } catch (Throwable $e) {
            // Redis is on the login path: staff cannot sign in without it.
            return OpsCheck::gawat('redis', 'Cache/antrean (Redis)', 'Tidak bisa dihubungi: '.$e->getMessage());
        }
    }

    private function antrean(): OpsCheck
    {
        try {
            $menunggu = (int) Queue::size();

            return match (true) {
                $menunggu > 200 => OpsCheck::gawat('antrean', 'Antrean kerja', "{$menunggu} job menunggu — pekerja mati atau macet"),
                $menunggu > 50 => OpsCheck::waspada('antrean', 'Antrean kerja', "{$menunggu} job menunggu"),
                default => OpsCheck::sehat('antrean', 'Antrean kerja', "{$menunggu} job menunggu"),
            };
        } catch (Throwable $e) {
            return OpsCheck::gawat('antrean', 'Antrean kerja', 'Tidak terukur: '.$e->getMessage());
        }
    }

    private function pekerjaanGagal(): OpsCheck
    {
        try {
            $gagal = (int) DB::table('failed_jobs')->count();

            return match (true) {
                $gagal > 10 => OpsCheck::gawat('pekerjaan_gagal', 'Job gagal', "{$gagal} job gagal menumpuk — php artisan queue:failed"),
                $gagal > 0 => OpsCheck::waspada('pekerjaan_gagal', 'Job gagal', "{$gagal} job gagal — php artisan queue:failed"),
                default => OpsCheck::sehat('pekerjaan_gagal', 'Job gagal', 'Tidak ada'),
            };
        } catch (Throwable $e) {
            return OpsCheck::gawat('pekerjaan_gagal', 'Job gagal', 'Tidak terukur: '.$e->getMessage());
        }
    }

    private function scheduler(): OpsCheck
    {
        try {
            $terakhir = Cache::get(self::HEARTBEAT_KEY);

            if ($terakhir === null) {
                return OpsCheck::gawat(
                    'scheduler', 'Scheduler',
                    'Tidak ada detak. Cron schedule:run tidak jalan — reservasi basi tidak pernah dilepas.',
                );
            }

            $menit = (int) floor((time() - (int) $terakhir) / 60);

            return match (true) {
                $menit >= 15 => OpsCheck::gawat('scheduler', 'Scheduler', "Detak terakhir {$menit} menit lalu"),
                $menit >= 5 => OpsCheck::waspada('scheduler', 'Scheduler', "Detak terakhir {$menit} menit lalu"),
                default => OpsCheck::sehat('scheduler', 'Scheduler', "Detak terakhir {$menit} menit lalu"),
            };
        } catch (Throwable $e) {
            return OpsCheck::gawat('scheduler', 'Scheduler', 'Tidak terukur: '.$e->getMessage());
        }
    }

    private function cadangan(): OpsCheck
    {
        try {
            $terakhir = BackupRun::query()
                ->where('status', BackupRun::STATUS_VERIFIED)
                ->where('offsite', true)
                ->latest('created_at')
                ->first();

            if ($terakhir === null) {
                return OpsCheck::gawat('cadangan', 'Cadangan', 'Belum pernah ada cadangan terverifikasi di luar server');
            }

            $jam = (int) floor($terakhir->created_at->diffInHours(now()));

            return match (true) {
                // Nightly cadence: two missed nights is an incident, one is a warning.
                $jam > 50 => OpsCheck::gawat('cadangan', 'Cadangan', "Terakhir {$jam} jam lalu — dua malam terlewat"),
                $jam > 26 => OpsCheck::waspada('cadangan', 'Cadangan', "Terakhir {$jam} jam lalu"),
                default => OpsCheck::sehat('cadangan', 'Cadangan', "Terakhir {$jam} jam lalu"),
            };
        } catch (Throwable $e) {
            return OpsCheck::gawat('cadangan', 'Cadangan', 'Tidak terukur: '.$e->getMessage());
        }
    }

    private function disk(): OpsCheck
    {
        try {
            $path = storage_path();
            $bebas = disk_free_space($path);
            $total = disk_total_space($path);

            if ($bebas === false || $total === false || $total <= 0) {
                return OpsCheck::gawat('disk', 'Ruang disk', 'Tidak terukur');
            }

            $persen = (int) round($bebas / $total * 100);
            $gb = round($bebas / 1_073_741_824, 1);

            // Thresholds from config so an operator with a deliberately
            // small volume can tune them — and so tests can pin them.
            $gawat = (int) config('ops.disk_gawat_persen', 10);
            $waspada = (int) config('ops.disk_waspada_persen', 20);

            return match (true) {
                $persen < $gawat => OpsCheck::gawat('disk', 'Ruang disk', "Sisa {$persen}% ({$gb} GB) — pg_dump malam ini bisa gagal"),
                $persen < $waspada => OpsCheck::waspada('disk', 'Ruang disk', "Sisa {$persen}% ({$gb} GB)"),
                default => OpsCheck::sehat('disk', 'Ruang disk', "Sisa {$persen}% ({$gb} GB)"),
            };
        } catch (Throwable $e) {
            return OpsCheck::gawat('disk', 'Ruang disk', 'Tidak terukur: '.$e->getMessage());
        }
    }
}
