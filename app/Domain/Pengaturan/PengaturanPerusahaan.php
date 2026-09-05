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

    /**
     * Bounded rather than forever, and the bound is the point.
     *
     * Saving forgets this key, so a minute of staleness is normally
     * impossible. It becomes possible in exactly one situation: the cache is
     * unreachable when somebody saves, so the forget cannot land, and the old
     * value is still sitting there when the cache comes back. Kept forever
     * that value would be served until someone thought to flush by hand —
     * for settings whose whole purpose is to be right on a printed document.
     *
     * A minute of a stale rekening is a bad minute; an indefinite one is a
     * bad quarter. The cost of the bound is one `pluck` a minute.
     */
    public const CACHE_TTL = 60;

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

        // The partner list on the public site, stored as a JSON array of
        // {nama, negara, sejak, bidang, deskripsi}. The one non-scalar key:
        // naming a company as a partner is a claim about a real business
        // relationship, and the Owner must be able to make — or retract —
        // that claim from the screen, not from a config file over SSH.
        'mitra_json' => 'perusahaan.mitra',
    ];

    /** Keys whose stored value is a JSON document, not a scalar string. */
    private const KUNCI_JSON = ['mitra_json'];

    /**
     * What the Owner has stored, from the cache if it is there and from the
     * database if it is not. Null only when neither can answer.
     *
     * **The cache is an optimisation over one `pluck`, and it used to be
     * treated as the source.** When Redis was unreachable this gave up and
     * left config answering — which sounds harmless and is not, because what
     * config answers is the placeholder:
     *
     *     Redis up    rekening.nomor '1234567890'  bank 'BCA CABANG SURABAYA'
     *     Redis down  rekening.nomor ''            bank 'BCA'
     *
     * `config/perusahaan.php` says of that value: *a wrong number here sends
     * customer money to somebody else's account*. So a cache outage silently
     * printed the placeholder rekening on every faktur, the placeholder NPWP
     * on every faktur pajak, and the placeholder legal identity on the public
     * site — with the documents rendering perfectly and nothing raised.
     *
     * Falling back to the database keeps every one of those right and loses
     * only the caching. The database failing too is the case the original
     * guard was written for — a fresh clone mid-migration — and that still
     * returns null and leaves config alone.
     *
     * @return array<string, string|null>|null
     */
    private function tersimpan(): ?array
    {
        try {
            return Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL,
                fn () => Pengaturan::query()->pluck('nilai', 'kunci')->all(),
            );
        } catch (Throwable) {
            // The cache is gone. The answer is not.
        }

        try {
            return Pengaturan::query()->pluck('nilai', 'kunci')->all();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lay stored values over config. Called at boot, before any request
     * reads the keys; guarded because boot also happens with no database —
     * a fresh clone mid-migration must not fatal on its own settings.
     */
    public function overlay(): void
    {
        $tersimpan = $this->tersimpan();

        if ($tersimpan === null) {
            return;
        }

        foreach ($tersimpan as $kunci => $nilai) {
            $path = self::PETA[$kunci] ?? null;

            // A blank is "never filled in", not "override with nothing":
            // the config/env fallback keeps answering.
            if ($path === null || $nilai === null || trim((string) $nilai) === '') {
                continue;
            }

            /*
             * A JSON key decodes before it lands, and an empty array is a
             * real answer, not a blank: "no partners" is a choice the Owner
             * makes on purpose, distinct from never having opened the screen
             * — which stays on the config placeholder the launch checklist
             * flags.
             */
            if (in_array($kunci, self::KUNCI_JSON, true)) {
                $decoded = json_decode((string) $nilai, true);

                if (is_array($decoded)) {
                    config([$path => $decoded]);
                }

                continue;
            }

            config([$path => $nilai]);
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

                // A JSON key arrives from its Repeater as an array; it is
                // stored encoded, and `[]` survives — see overlay().
                if (in_array($kunci, self::KUNCI_JSON, true)) {
                    $nilai = $nilai === null
                        ? null
                        : json_encode(array_values((array) $nilai), JSON_UNESCAPED_UNICODE);
                } else {
                    $nilai = $nilai === null ? null : trim((string) $nilai);
                }

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

        /*
         * The rows are committed by now, so a cache that cannot be reached
         * must not turn a saved change into an exception. The bounded TTL
         * above is what makes swallowing this safe: the stale value ages out
         * on its own rather than outliving the outage.
         */
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Nothing to do about it here, and nothing worth failing over.
        }

        $this->overlay();
    }
}
