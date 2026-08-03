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
     * Title rows name the category. The known list is preferred, but an
     * unrecognised title is still carried forward — the importer flags rows
     * whose category never got detected rather than inventing one.
     */
    public function categoryFromTitle(string $title): string
    {
        $normalised = strtoupper(preg_replace('/\s+/', ' ', trim($title)));

        foreach ((array) config('pricelist.known_categories') as $known) {
            if (str_contains($normalised, strtoupper($known))) {
                return strtoupper($known);
            }
        }

        return $normalised;
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
