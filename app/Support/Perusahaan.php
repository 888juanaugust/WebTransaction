<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads company profile content out of config/perusahaan.php in a given
 * language.
 *
 * The public site is mixed by design: the home page is English, every other
 * page and both panels are Bahasa Indonesia. Rather than duplicate the whole
 * config or switch the app locale per route — which would also flip Filament,
 * dates and validation messages — prose is stored as ['id' => …, 'en' => …]
 * and read through here.
 *
 * Values that are the same in either language (a company name, a brand, a
 * phone number, a year) stay plain strings in the config and pass through
 * untouched. That keeps proper nouns from being duplicated, and means adding a
 * new field does not force a translation that does not exist.
 */
final class Perusahaan
{
    public const ID = 'id';

    public const EN = 'en';

    /**
     * A single value, in the requested language.
     *
     * Falls back to Indonesian when a translation is missing, because a
     * missing English string should degrade to the language we actually have
     * rather than to an empty page.
     */
    public static function text(string $key, string $lang = self::ID): string
    {
        return (string) self::pick(config("perusahaan.{$key}"), $lang);
    }

    /**
     * A list value — paragraphs of profile copy, for instance.
     *
     * @return list<string>
     */
    public static function list(string $key, string $lang = self::ID): array
    {
        $value = self::pick(config("perusahaan.{$key}"), $lang);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * A list of records with some translatable fields, flattened to the
     * requested language — categories and partners.
     *
     * @return list<array<string, mixed>>
     */
    public static function records(string $key, string $lang = self::ID): array
    {
        $rows = config("perusahaan.{$key}", []);

        return array_map(
            fn (array $row) => array_map(fn ($value) => self::pick($value, $lang), $row),
            array_values($rows),
        );
    }

    /**
     * Resolve one value: a translation map picks a language, anything else is
     * returned as it stands.
     */
    private static function pick(mixed $value, string $lang): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // A translation map, as opposed to a plain list such as `merk`.
        if (array_key_exists(self::ID, $value) || array_key_exists(self::EN, $value)) {
            return $value[$lang] ?? $value[self::ID] ?? reset($value);
        }

        return $value;
    }
}
