<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads company profile content out of config/perusahaan.php, in the
 * language the public site is currently speaking.
 *
 * The site is bilingual again (2026-09): Bahasa Indonesia by default, English
 * on request. So the copy that is *about the company* — tagline, profile,
 * category blurbs, the roadmap — is stored as `['id' => …, 'en' => …]`
 * pairs, and this is the one place that picks a side. Views never touch the
 * pair; they ask for `text('tagline')` and get a sentence.
 *
 * A plain string is still accepted everywhere a pair is: the partners list
 * is typed by the Owner in Pengaturan in one language, and a value that has
 * only ever been one sentence should keep being that sentence in both.
 *
 * The drift the old single-language file was collapsed to avoid is guarded
 * differently now — by a test that walks the config and requires every pair
 * to carry both keys, so an English sentence with no Indonesian twin fails
 * the suite rather than showing up as a blank on the home page.
 */
final class Perusahaan
{
    public static function text(string $key): string
    {
        return (string) self::pilih(config("perusahaan.{$key}", ''));
    }

    /**
     * A list value — the paragraphs of profile copy, for instance.
     *
     * @return list<string>
     */
    public static function list(string $key): array
    {
        $value = config("perusahaan.{$key}", []);

        if (! is_array($value)) {
            return [];
        }

        // A pair of lists (['id' => [...], 'en' => [...]]) or a list of pairs.
        if (self::adalahPasangan($value)) {
            $value = self::pilih($value);
        }

        return array_values(array_map(fn ($v) => (string) self::pilih($v), is_array($value) ? $value : []));
    }

    /**
     * A list of records — categories, partners, the roadmap — with any
     * bilingual field inside each record already resolved.
     *
     * @return list<array<string, mixed>>
     */
    public static function records(string $key): array
    {
        $records = (array) config("perusahaan.{$key}", []);

        return array_values(array_map(
            fn ($record) => is_array($record)
                ? array_map(fn ($field) => self::pilih($field), $record)
                : $record,
            $records,
        ));
    }

    /**
     * The business hours in the current language.
     *
     * Two facts for two audiences rather than a pair: the Indonesian hours
     * are printed on Indonesian documents whatever the site is speaking, so
     * they keep their own key.
     */
    public static function jamOperasional(): string
    {
        return app()->getLocale() === 'en'
            ? (string) config('perusahaan.kontak.business_hours', '')
            : (string) config('perusahaan.kontak.jam_operasional', '');
    }

    /**
     * A pair resolved to the current language, anything else passed through.
     *
     * Falls back to Indonesian, then to whichever side exists: a pair with
     * one side missing is a content bug the test catches, and until it is
     * fixed the page should still say something rather than nothing.
     */
    private static function pilih(mixed $value): mixed
    {
        if (! self::adalahPasangan($value)) {
            return $value;
        }

        /** @var array<string, mixed> $value */
        return $value[app()->getLocale()]
            ?? $value['id']
            ?? (array_values($value)[0] ?? '');
    }

    /** An array keyed only by language codes. */
    private static function adalahPasangan(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }

        foreach (array_keys($value) as $k) {
            if (! in_array($k, ['id', 'en'], true)) {
                return false;
            }
        }

        return true;
    }
}
