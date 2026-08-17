<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * What every report returns: named columns, rows, and a totals row.
 *
 * A shared shape rather than four bespoke ones, so the screens and the CSV
 * writer are written once. The alternative — each report shaping its own
 * output — means the export has to know about four things and the fifth report
 * makes it five.
 *
 * Columns carry their own alignment and formatter because a report is mostly
 * money and quantities, and a rupiah figure left-aligned in a column of them
 * is unreadable. Keeping that on the column rather than in the view means the
 * CSV and the screen cannot disagree about what a cell says.
 */
final class ReportTable
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  list<string>  $catatan  caveats a reader needs before believing the figures
     */
    public function __construct(
        public readonly string $judul,
        public readonly Period $period,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $catatan = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function hasTotals(): bool
    {
        return $this->totals !== [];
    }

    /** Column definitions the current user is allowed to see. */
    public function visibleColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            fn (ReportColumn $column) => $column->isVisible(),
        ));
    }
}
