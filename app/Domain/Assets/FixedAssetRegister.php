<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Expenses\PaidFrom;
use App\Models\FixedAsset;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Buying something the business will use rather than sell, and eventually
 * getting rid of it.
 *
 * The gap this closes sits next to the one the expense screen closed. Recording
 * a delivery van had nowhere to go: the expense form refuses non-expense
 * accounts on purpose, and rightly — a van is not a cost of August, it is an
 * asset that becomes a cost over eight years. So the van simply never reached
 * the books, and neither did the depreciation on it.
 */
class FixedAssetRegister
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function acquire(
        string $nama,
        DepreciationGroup $kelompok,
        DateTimeInterface $tanggal,
        int $hargaPerolehan,
        PaidFrom $paidFrom,
        User $actor,
        string $kategori = 'lainnya',
        int $nilaiResidu = 0,
        ?string $keterangan = null,
    ): FixedAsset {
        $this->assertMayManage($actor);

        if ($hargaPerolehan <= 0) {
            throw new DomainException('Harga perolehan harus lebih dari nol.');
        }

        if ($nilaiResidu < 0) {
            throw new DomainException('Nilai residu tidak boleh negatif.');
        }

        if ($nilaiResidu >= $hargaPerolehan) {
            throw new DomainException(
                'Nilai residu harus lebih kecil dari harga perolehan — kalau tidak, tidak ada yang disusutkan.'
            );
        }

        if (trim($nama) === '') {
            throw new DomainException('Aktiva tetap harus punya nama.');
        }

        $date = Carbon::parse($tanggal)->startOfDay();

        if ($date->isFuture()) {
            throw new DomainException('Tanggal perolehan belum lewat.');
        }

        return DB::transaction(function () use (
            $nama, $kelompok, $date, $hargaPerolehan, $paidFrom, $actor, $kategori, $nilaiResidu, $keterangan
        ) {
            $asset = FixedAsset::create([
                'nomor' => $this->numbers->nextFixedAssetNumber($date),
                'nama' => trim($nama),
                'keterangan' => $keterangan,
                'kelompok' => $kelompok,
                // Frozen from the group at registration. A later change to the
                // enum must not re-depreciate things bought years ago.
                'masa_manfaat_bulan' => $kelompok->months(),
                'tanggal_perolehan' => $date,
                'harga_perolehan_rupiah' => $hargaPerolehan,
                'nilai_residu_rupiah' => $nilaiResidu,
                'kategori' => $kategori,
                'dibayar_dari' => $paidFrom,
                'created_by' => $actor->id,
            ]);

            $this->poster->assetAcquired($asset->refresh(), $actor);

            $this->audit->log(
                action: 'fixed_asset_acquired',
                subject: $asset,
                newValue: [
                    'nomor' => $asset->nomor,
                    'nama' => $asset->nama,
                    'kelompok' => $kelompok->value,
                    'harga_perolehan_rupiah' => $hargaPerolehan,
                ],
                actor: $actor,
            );

            return $asset->refresh();
        });
    }

    /**
     * Sold, scrapped or written off.
     *
     * The entry takes the asset's cost and its accumulated depreciation off the
     * books together, brings in whatever it sold for, and lands the difference
     * in one place. That difference is a real gain or loss and it belongs
     * nowhere near Penjualan — selling the old van is not trade, and putting it
     * through the top line would inflate the figure every margin divides into.
     *
     * Scrapping is the same journal with proceeds of nil, which is why there is
     * no separate method for it.
     */
    public function dispose(
        FixedAsset $asset,
        DateTimeInterface $tanggal,
        int $hargaJual,
        User $actor,
        string $alasan,
        ?PaidFrom $proceedsTo = null,
    ): FixedAsset {
        $this->assertMayManage($actor);

        if (! $asset->isActive()) {
            throw new DomainException("Aktiva {$asset->nomor} sudah dilepas.");
        }

        if ($hargaJual < 0) {
            throw new DomainException('Harga jual tidak boleh negatif. Untuk barang yang dibuang, isi nol.');
        }

        if (trim($alasan) === '') {
            throw new DomainException('Pelepasan aktiva harus menyebutkan alasannya.');
        }

        $date = Carbon::parse($tanggal)->startOfDay();

        if ($date->lessThan(Carbon::parse($asset->tanggal_perolehan))) {
            throw new DomainException('Tanggal pelepasan tidak boleh sebelum tanggal perolehan.');
        }

        if ($date->isFuture()) {
            throw new DomainException('Tanggal pelepasan belum lewat.');
        }

        return DB::transaction(function () use ($asset, $date, $hargaJual, $actor, $alasan, $proceedsTo) {
            $locked = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);

            /*
             * Checked again under the lock. The check above catches the
             * ordinary case and this one catches two people disposing of the
             * same asset at once — which no single-threaded test can reach, so
             * mutation testing reports it as removable. It is not: without it
             * the second disposal would post a second journal entry taking the
             * cost off the books twice.
             */
            if (! $locked->isActive()) {
                throw new DomainException("Aktiva {$locked->nomor} sudah dilepas.");
            }

            $locked->forceFill([
                'status' => FixedAsset::STATUS_DILEPAS,
                'tanggal_pelepasan' => $date,
                'harga_jual_rupiah' => $hargaJual,
                'alasan_pelepasan' => trim($alasan),
                'disposed_by' => $actor->id,
            ])->save();

            $this->poster->assetDisposed(
                $locked->refresh(),
                $actor,
                $proceedsTo ?? $locked->dibayar_dari,
            );

            $this->audit->log(
                action: 'fixed_asset_disposed',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'tanggal_pelepasan' => $date->toDateString(),
                    'harga_jual_rupiah' => $hargaJual,
                    'nilai_buku' => $locked->bookValue(),
                    'alasan' => trim($alasan),
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    private function assertMayManage(User $actor): void
    {
        if (! $actor->role()->canPostJournals()) {
            throw new DomainException('Anda tidak berhak mengelola aktiva tetap.');
        }
    }
}
