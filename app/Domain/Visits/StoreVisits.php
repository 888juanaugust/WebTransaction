<?php

declare(strict_types=1);

namespace App\Domain\Visits;

use App\Domain\Access\Role;
use App\Domain\Audit\AuditLogger;
use App\Models\Company;
use App\Models\StoreVisit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Kunjungan toko: recording, retention, and the archive.
 *
 * A visit is recorded by the sales who made it, for a store they hold —
 * the same seat rule as everything else they file. The photo is the proof
 * and the heaviest part, so it lives on disk under a 2-month retention:
 * a scheduled purge deletes the files and stamps the rows, while the visit
 * itself (who, where, when) stays queryable forever. Before a month's
 * photos go, admin or finance can pull them as a zip.
 */
class StoreVisits
{
    /** Photos are kept this many days, then purged. */
    public const RETENTION_DAYS = 62;

    public const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    public function record(
        User $sales,
        Company $company,
        ?float $latitude,
        ?float $longitude,
        ?string $fotoPath,
        ?string $catatan = null,
    ): StoreVisit {
        if ($sales->role() !== Role::Sales) {
            throw new \DomainException('Kunjungan dicatat oleh sales yang berkunjung.');
        }

        if ((int) $company->sales_user_id !== (int) $sales->getKey()) {
            throw new \DomainException("Pelanggan {$company->nama} bukan tanggung jawab Anda — kunjungannya bukan kunjungan Anda.");
        }

        return StoreVisit::query()->create([
            'sales_user_id' => $sales->id,
            'company_id' => $company->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'foto_path' => $fotoPath,
            'catatan' => $catatan,
            // The moment of recording, not a typed-in time: the timestamp is
            // evidence, and evidence is not editable.
            'visited_at' => now(),
        ]);
    }

    /**
     * Delete photos past retention. The rows stay, stamped with when their
     * photo went, so the history keeps its shape without its weight.
     */
    public function purgeExpiredPhotos(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);
        $purged = 0;

        StoreVisit::query()
            ->withoutGlobalScope('region')
            ->whereNotNull('foto_path')
            ->whereNull('foto_dihapus_pada')
            ->where('visited_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($visits) use (&$purged) {
                foreach ($visits as $visit) {
                    Storage::disk(self::DISK)->delete($visit->foto_path);
                    $visit->forceFill(['foto_dihapus_pada' => now()])->save();
                    $purged++;
                }
            });

        if ($purged > 0) {
            Log::info('Foto kunjungan melewati retensi dihapus', ['jumlah' => $purged]);

            $this->audit->log(
                action: 'visit_photos_purged',
                newValue: ['jumlah' => $purged, 'retensi_hari' => self::RETENTION_DAYS],
            );
        }

        return $purged;
    }

    /**
     * Zip one month's visits — the photos plus a CSV of who/where/when —
     * for admin or finance to file away before the purge takes the files.
     * Returns the absolute path of the finished zip.
     */
    public function archiveMonth(Carbon $bulan, User $actor): string
    {
        if (! in_array($actor->role(), [Role::Owner, Role::Finance], true)) {
            throw new \DomainException('Arsip kunjungan hanya untuk pemilik dan finance.');
        }

        $visits = StoreVisit::query()
            ->withoutGlobalScope('region')
            ->with(['sales', 'company'])
            ->whereBetween('visited_at', [$bulan->copy()->startOfMonth(), $bulan->copy()->endOfMonth()])
            ->orderBy('visited_at')
            ->get();

        if ($visits->isEmpty()) {
            throw new \DomainException('Tidak ada kunjungan pada bulan itu.');
        }

        $dir = storage_path('app/arsip-kunjungan');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        $zipPath = $dir.'/kunjungan-'.$bulan->format('Y-m').'.zip';

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $csv = "visited_at,sales,pelanggan,latitude,longitude,catatan,foto\n";

        foreach ($visits as $visit) {
            $fotoName = null;

            if ($visit->foto_path !== null
                && $visit->foto_dihapus_pada === null
                && Storage::disk(self::DISK)->exists($visit->foto_path)) {
                $fotoName = sprintf(
                    '%s-%s%s',
                    $visit->visited_at->format('Ymd-His'),
                    $visit->id,
                    '.'.pathinfo($visit->foto_path, PATHINFO_EXTENSION),
                );
                $zip->addFromString('foto/'.$fotoName, Storage::disk(self::DISK)->get($visit->foto_path));
            }

            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s\n",
                $visit->visited_at->toDateTimeString(),
                str_replace(',', ' ', (string) $visit->sales?->name),
                str_replace(',', ' ', (string) $visit->company?->nama),
                $visit->latitude ?? '',
                $visit->longitude ?? '',
                str_replace([',', "\n"], [' ', ' '], (string) $visit->catatan),
                $fotoName ?? '(sudah dihapus)',
            );
        }

        $zip->addFromString('kunjungan.csv', $csv);
        $zip->close();

        $this->audit->log(
            action: 'visits_archived',
            newValue: ['bulan' => $bulan->format('Y-m'), 'jumlah' => $visits->count()],
            actor: $actor,
        );

        return $zipPath;
    }
}
