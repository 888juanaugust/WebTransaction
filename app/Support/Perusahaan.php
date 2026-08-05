<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads company profile content out of config/perusahaan.php.
 *
 * The site is Bahasa Indonesia throughout, so this no longer chooses a
 * language. It exists to give the views one way to reach the content, and to
 * keep string-keyed config() calls out of the templates.
 *
 * It used to take a $lang, back when the home page alone was English. That is
 * gone: one sentence with two versions is one sentence that drifts.
 */
final class Perusahaan
{
    public static function text(string $key): string
    {
        return (string) config("perusahaan.{$key}", '');
    }

    /**
     * A list value — the paragraphs of profile copy, for instance.
     *
     * @return list<string>
     */
    public static function list(string $key): array
    {
        $value = config("perusahaan.{$key}", []);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * A list of records — categories and partners.
     *
     * @return list<array<string, mixed>>
     */
    public static function records(string $key): array
    {
        return array_values((array) config("perusahaan.{$key}", []));
    }
}
