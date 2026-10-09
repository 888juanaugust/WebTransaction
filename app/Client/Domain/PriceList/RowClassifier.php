<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

/**
 * What a row of the supplier's sheet is: blank, one of the headers scattered
 * through the file, a title (the sheet's banner, a category, or a product
 * type), or data. Category and product type are not columns in that file;
 * they are the nearest title above.
 */
final class RowClassifier
{
    public const BLANK = 'blank';

    public const HEADER = 'header';

    public const TITLE = 'title';

    public const DATA = 'data';

    public const TITLE_CATEGORY = 'title_category';

    public const TITLE_TIPE = 'title_tipe';

    public const TITLE_BANNER = 'title_banner';

    /** @param  list<mixed>  $cells */
    public static function classify(array $cells): string
    {
        $filled = array_values(array_filter(array_map(fn ($c) => trim((string) ($c ?? '')), $cells), fn (string $c) => $c !== ''));
        if ($filled === []) {
            return self::BLANK;
        }
        if (self::looksLikeHeader($filled)) {
            return self::HEADER;
        }
        if (count($filled) === 1 && ! self::looksNumeric($filled[0])) {
            return self::TITLE;
        }

        return self::DATA;
    }

    /** Banner, category or product type. */
    public static function titleKind(string $title): string
    {
        $normalised = self::normaliseTitle($title);
        foreach ((array) config('pricelist.title_ignore_patterns', []) as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return self::TITLE_BANNER;
            }
        }

        return self::categoryFromTitle($title) !== null ? self::TITLE_CATEGORY : self::TITLE_TIPE;
    }

    /** The known category a title names, as the company spells it. */
    public static function categoryFromTitle(string $title): ?string
    {
        $normalised = self::normaliseTitle($title);
        foreach ((array) config('pricelist.known_categories', []) as $known) {
            if (str_contains($normalised, strtoupper((string) $known))) {
                return strtoupper((string) $known);
            }
        }

        return null;
    }

    /** A product type as typed, a leading * included. */
    public static function tipeFromTitle(string $title): string
    {
        return self::normaliseTitle($title);
    }

    /** @param  list<string>  $filled */
    private static function looksLikeHeader(array $filled): bool
    {
        $tokens = array_map('strval', (array) config('pricelist.header_tokens', []));
        $hits = count(array_filter($filled, fn (string $cell) => in_array(self::normalise($cell), $tokens, true)));

        return $hits >= (int) config('pricelist.header_token_threshold', 3);
    }

    private static function normalise(string $cell): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($cell)));
    }

    private static function normaliseTitle(string $title): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $title)));
    }

    private static function looksNumeric(string $cell): bool
    {
        return is_numeric(str_replace([',', '.', ' '], '', $cell));
    }
}
