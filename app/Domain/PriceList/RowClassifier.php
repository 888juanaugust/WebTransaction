<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

/**
 * Decides what a raw spreadsheet row *is*, without trusting what it says.
 *
 * The supplier workbook has ~55 repeated header rows scattered mid-file, and
 * at least two of them are mislabeled — the header text disagrees with the
 * data below it. So header text is used only to notice "this row is a header,
 * skip it", never to decide what any column means.
 */
class RowClassifier
{
    public const BLANK = 'blank';

    public const HEADER = 'header';

    public const TITLE = 'title';

    public const DATA = 'data';

    /** What a TITLE row turned out to name — see titleKind(). */
    public const TITLE_CATEGORY = 'title_category';

    public const TITLE_TIPE = 'title_tipe';

    public const TITLE_BANNER = 'title_banner';

    /** @var list<string> */
    private array $headerTokens;

    private int $threshold;

    public function __construct(?array $headerTokens = null, ?int $threshold = null)
    {
        $this->headerTokens = $headerTokens ?? config('pricelist.header_tokens');
        $this->threshold = $threshold ?? (int) config('pricelist.header_token_threshold');
    }

    /**
     * @param  list<string|null>  $cells
     */
    public function classify(array $cells): string
    {
        $filled = array_values(array_filter(
            array_map(fn ($c) => trim((string) $c), $cells),
            fn ($c) => $c !== '',
        ));

        if ($filled === []) {
            return self::BLANK;
        }

        if ($this->looksLikeHeader($filled)) {
            return self::HEADER;
        }

        // A title-only row is one populated cell carrying a label — that is how
        // categories appear, since KATEGORI is not a column in this file.
        if (count($filled) === 1 && ! $this->looksNumeric($filled[0])) {
            return self::TITLE;
        }

        return self::DATA;
    }

    /**
     * @param  list<string>  $filled
     */
    public function looksLikeHeader(array $filled): bool
    {
        $hits = 0;

        foreach ($filled as $cell) {
            if (in_array($this->normalise($cell), $this->headerTokens, true)) {
                $hits++;
            }
        }

        return $hits >= $this->threshold;
    }

    /**
     * What a title row is.
     *
     * The workbook stacks two levels of title above each block, plus a banner
     * at the top of every sheet:
     *
     *     PRICE LIST YUHOLI                  <- banner, ignore
     *     *HARGA SEWAKTU WAKTU BISA BERUBAH  <- banner, ignore
     *     HYDRAULIC PART                     <- category
     *     BRAKE MASTER / BM ASSY / PUSAT     <- product type
     *
     * Only a title naming one of the four known categories is a category.
     * Everything else is a product type — and it must not be allowed to
     * overwrite the category, or every row ends up filed under BRAKE MASTER
     * with the real category thrown away.
     */
    public function titleKind(string $title): string
    {
        $normalised = self::normaliseTitle($title);

        foreach ((array) config('pricelist.title_ignore_patterns') as $pattern) {
            if (preg_match($pattern, $normalised) === 1) {
                return self::TITLE_BANNER;
            }
        }

        return $this->categoryFromTitle($title) === null
            ? self::TITLE_TIPE
            : self::TITLE_CATEGORY;
    }

    /**
     * The category a title names, or null when it names none.
     *
     * Null rather than "the title, uppercased": returning the text meant a
     * product-type row was carried forward as a category, so the row looked
     * categorised when it was not. A row whose category was never detected is
     * a blocker, and it can only be one if this is allowed to say "no".
     */
    public function categoryFromTitle(string $title): ?string
    {
        $normalised = self::normaliseTitle($title);

        foreach ((array) config('pricelist.known_categories') as $known) {
            if (str_contains($normalised, strtoupper((string) $known))) {
                return strtoupper((string) $known);
            }
        }

        return null;
    }

    /** The product type a title names, tidied but otherwise as written. */
    public function tipeFromTitle(string $title): string
    {
        return self::normaliseTitle($title);
    }

    private static function normaliseTitle(string $title): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $title) ?? ''));
    }

    private function normalise(string $cell): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($cell)));
    }

    private function looksNumeric(string $cell): bool
    {
        return is_numeric(str_replace([',', '.', ' '], '', $cell));
    }
}
