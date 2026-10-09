<?php

declare(strict_types=1);

namespace App\Client\Site;

use App\Domain\Company\CompanyIdentity;

/**
 * The public site's copy in the language it is speaking: config/site.php
 * (app/Client/config/site.php), with the Owner's overrides on top. A pair
 * is an array keyed only `id` and `en`; a view asks for text('tagline') and
 * gets a sentence, never the pair.
 */
final class Copy
{
    /** @var list<string> */
    public const LANGUAGES = ['id', 'en'];

    /** The company's legal name, from Preferences (the letterhead). */
    public static function name(): string
    {
        return app(CompanyIdentity::class)->letterhead()['name'];
    }

    public static function shortName(): string
    {
        return (string) (self::value('short_name') ?: self::name());
    }

    public static function text(string $key): string
    {
        return (string) self::pick(self::value($key));
    }

    /** A list: a pair of lists, or a list of pairs. @return list<string> */
    public static function list(string $key): array
    {
        $value = self::value($key);
        if (! is_array($value)) {
            return [];
        }
        if (self::isPair($value)) {
            $value = self::pick($value);
        }

        return array_values(array_map(fn ($v) => (string) self::pick($v), is_array($value) ? $value : []));
    }

    /** A list of records with every pair inside resolved. @return list<array<string, mixed>> */
    public static function records(string $key): array
    {
        $records = self::value($key);

        return array_values(array_map(
            fn ($record) => is_array($record) ? array_map(fn ($field) => self::pick($field), $record) : $record,
            is_array($records) ? $records : [],
        ));
    }

    /** The raw value: the Owner's setting when there is one, else the config. */
    public static function value(string $key): mixed
    {
        return app(SiteSettings::class)->value($key) ?? config("site.{$key}");
    }

    /** A pair resolved to the current language; anything else passed through. Falls back to Indonesian, then to whichever side exists. */
    public static function pick(mixed $value): mixed
    {
        if (! self::isPair($value)) {
            return $value;
        }

        return $value[app()->getLocale()] ?? $value['id'] ?? (array_values($value)[0] ?? '');
    }

    /** An array keyed only by language codes. */
    public static function isPair(mixed $value): bool
    {
        if (! is_array($value) || $value === []) {
            return false;
        }
        foreach (array_keys($value) as $k) {
            if (! in_array($k, self::LANGUAGES, true)) {
                return false;
            }
        }

        return true;
    }

    /** The other language, for the switch: the one link it offers. */
    public static function otherLanguage(): string
    {
        return app()->getLocale() === 'en' ? 'id' : 'en';
    }

    /** Digits only, for a wa.me link. */
    public static function digits(string $number): string
    {
        return (string) preg_replace('/[^0-9]/', '', $number);
    }
}
