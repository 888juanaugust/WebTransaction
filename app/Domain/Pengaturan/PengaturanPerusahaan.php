<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

use App\Domain\Audit\AuditLogger;
use App\Models\Pengaturan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The values only the business knows, typed in by the Owner.
 *
 * Stored rows overlay their config keys at boot, so every existing consumer
 * — the faktur's payment block, the portal's Cara pembayaran, the public
 * site's contact page, the launch checklist, the terms' late-payment rate —
 * keeps reading `config()` and simply starts seeing what the Owner typed.
 * `.env` stays as the fallback for anything never saved here, which is also
 * what keeps a fresh install and the whole test suite working unchanged.
 *
 * The map below is the contract: a screen cannot invent a key, and a key
 * cannot land outside the config paths listed here.
 */
class PengaturanPerusahaan
{
    public const CACHE_KEY = 'pengaturan:semua';

    /** kunci tersimpan → config path yang ditimpanya */
    public const PETA = [
        // Where customer money goes. Printed on every faktur.
        'rekening_bank' => 'perusahaan.rekening.bank',
        'rekening_nomor' => 'perusahaan.rekening.nomor',
        'rekening_atas_nama' => 'perusahaan.rekening.atas_nama',

        // Legal identity, shown on the public site once PSE-registered.
        'perusahaan_nib' => 'perusahaan.legal.nib',
        'perusahaan_npwp' => 'perusahaan.legal.npwp',

        // Contact details, public site + printed documents.
        'perusahaan_alamat' => 'perusahaan.kontak.alamat',
        'perusahaan_kota' => 'perusahaan.kontak.kota',
        'perusahaan_telepon' => 'perusahaan.kontak.telepon',
        'perusahaan_whatsapp' => 'perusahaan.kontak.whatsapp',
        'perusahaan_email' => 'perusahaan.kontak.email',

        // The seller on every faktur pajak export.
        'pajak_penjual_npwp' => 'pajak.penjual.npwp',
        'pajak_penjual_nama' => 'pajak.penjual.nama',

        // The two >>> PUTUSKAN commercial values in the terms of sale.
        'legal_denda_persen' => 'legal.syarat.denda_persen_per_bulan',
        'legal_batas_klaim_hari' => 'legal.syarat.batas_klaim_hari',
    ];

    /**
     * Lay stored values over config. Called at boot, before any request
     * reads the keys; guarded because boot also happens with no database —
     * a fresh clone mid-migration must not fatal on its own settings.
     */
    public function overlay(): void
    {
        try {
            $tersimpan = Cache::rememberForever(
                self::CACHE_KEY,
                fn () => Pengaturan::query()->pluck('nilai', 'kunci')->all(),
            );
        } catch (Throwable) {
            return;
        }

        foreach ($tersimpan as $kunci => $nilai) {
            $path = self::PETA[$kunci] ?? null;

            // A blank is "never filled in", not "override with nothing":
            // the config/env fallback keeps answering.
            if ($path !== null && $nilai !== null && trim((string) $nilai) !== '') {
                config([$path => $nilai]);
            }
        }
    }

    /** The current effective value — whatever config sees after overlay. */
    public function nilai(string $kunci): mixed
    {
        $path = self::PETA[$kunci] ?? throw new InvalidArgumentException("Kunci pengaturan tidak dikenal: {$kunci}");

        return config($path);
    }

    /**
     * Save what the Owner typed, with the audit trail a money-bearing value
     * deserves: the bank account on the faktur is exactly the field a fraud
     * would edit, and "who changed it, from what, to what, when" is the
     * whole defence.
     *
     * @param  array<string, mixed>  $values  kunci => nilai
     */
    public function simpan(array $values, User $actor): void
    {
        $lama = [];
        $baru = [];

        DB::transaction(function () use ($values, $actor, &$lama, &$baru) {
            foreach ($values as $kunci => $nilai) {
                if (! array_key_exists($kunci, self::PETA)) {
                    throw new InvalidArgumentException("Kunci pengaturan tidak dikenal: {$kunci}");
                }

                $nilai = $nilai === null ? null : trim((string) $nilai);

                $row = Pengaturan::query()->firstWhere('kunci', $kunci);
                $sebelum = $row?->nilai;

                if ($sebelum === $nilai) {
                    continue;
                }

                Pengaturan::query()->updateOrCreate(
                    ['kunci' => $kunci],
                    ['nilai' => $nilai, 'updated_by' => $actor->id],
                );

                $lama[$kunci] = $sebelum;
                $baru[$kunci] = $nilai;
            }

            if ($baru !== []) {
                app(AuditLogger::class)->log(
                    action: 'pengaturan_diubah',
                    oldValue: $lama,
                    newValue: $baru,
                    actor: $actor,
                );
            }
        });

        Cache::forget(self::CACHE_KEY);
        $this->overlay();
    }
}
