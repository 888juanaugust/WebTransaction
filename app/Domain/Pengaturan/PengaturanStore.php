<?php

declare(strict_types=1);

namespace App\Domain\Pengaturan;

use App\Domain\Audit\AuditLogger;
use App\Models\Pengaturan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The `pengaturan` table: read through the cache with the database behind it,
 * written with an audit row. The one place both kinds of setting live —
 * the company's own values (PengaturanPerusahaan) and the ACCURATE-style
 * preferences and switches (Preferensi) — each owning its own keys.
 *
 * Extracted from PengaturanPerusahaan unchanged; the reasoning about the
 * cache that used to live there lives here now, beside the code it explains.
 */
final class PengaturanStore
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

    /**
     * Every stored row, from the cache if it is there and from the database
     * if it is not. Null only when neither can answer.
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
    public function semua(): ?array
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
     * Write already-validated values, and audit what actually changed under
     * `$action` with old and new side by side. An unchanged value writes
     * nothing and audits nothing.
     *
     * @param  array<string, string|null>  $nilai  kunci => stored form
     * @return array<string, string|null> the keys that changed, new values
     */
    public function tulis(string $action, array $nilai, User $actor): array
    {
        $lama = [];
        $baru = [];

        DB::transaction(function () use ($action, $nilai, $actor, &$lama, &$baru) {
            foreach ($nilai as $kunci => $isi) {
                $sebelum = Pengaturan::query()->firstWhere('kunci', $kunci)?->nilai;

                if ($sebelum === $isi) {
                    continue;
                }

                Pengaturan::query()->updateOrCreate(
                    ['kunci' => $kunci],
                    ['nilai' => $isi, 'updated_by' => $actor->id],
                );

                $lama[$kunci] = $sebelum;
                $baru[$kunci] = $isi;
            }

            if ($baru !== []) {
                app(AuditLogger::class)->log(
                    action: $action,
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

        return $baru;
    }
}
