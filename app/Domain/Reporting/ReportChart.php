<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * A report's picture: one series of labelled values, drawn as bars.
 *
 * Derived from the already-built ReportTable wherever possible, never from a
 * second query — a chart that runs its own SQL is a second implementation of
 * the report, and the day they disagree, whichever the reader saw first wins
 * the argument. The one exception (sales per region) says so where it lives.
 *
 * Rendered server-side as SVG by one Blade partial. No script, so the chart
 * prints with the report and appears in the same request the figures do.
 */
final class ReportChart
{
    /**
     * @param  list<string>  $labels
     * @param  list<int>  $values  rupiah or plain counts, per $rupiah
     */
    public function __construct(
        public readonly string $judul,
        public readonly array $labels,
        public readonly array $values,
        public readonly bool $rupiah = true,
        public readonly ?string $catatan = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->values === [] || max([0, ...$this->values]) <= 0;
    }

    public function max(): int
    {
        return max([1, ...$this->values]);
    }

    /**
     * The top rows of a table by one money column — the shape most report
     * charts take, because the question is nearly always "who matters".
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function topRows(
        string $judul,
        array $rows,
        string $labelKey,
        string $valueKey,
        int $limit = 10,
        bool $rupiah = true,
        ?string $catatan = null,
    ): self {
        $rows = array_values(array_filter($rows, fn ($r) => (int) ($r[$valueKey] ?? 0) > 0));
        usort($rows, fn ($a, $b) => (int) $b[$valueKey] <=> (int) $a[$valueKey]);
        $rows = array_slice($rows, 0, $limit);

        return new self(
            judul: $judul,
            labels: array_map(fn ($r) => (string) $r[$labelKey], $rows),
            values: array_map(fn ($r) => (int) $r[$valueKey], $rows),
            rupiah: $rupiah,
            catatan: $catatan,
        );
    }
}
