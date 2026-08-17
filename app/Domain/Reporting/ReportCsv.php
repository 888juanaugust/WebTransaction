<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * A report as a spreadsheet.
 *
 * Written once for every report, because the owner's next move after reading
 * one of these is always to put it in a spreadsheet and sort it differently.
 *
 * Semicolons, not commas. Indonesian Excel is usually set to a comma decimal
 * separator, which makes comma-delimited CSV open as one column per row — and
 * the person it happens to concludes the export is broken rather than that
 * their locale is. A UTF-8 BOM goes on the front for the same practical
 * reason: without it Excel reads `Gudang Cabang Bekasi` fine and mangles
 * anything with an accent in a supplier's name.
 */
class ReportCsv
{
    public function write(ReportTable $report): string
    {
        $columns = $report->visibleColumns();

        $lines = [];

        // A title row above the headers. These get emailed around detached
        // from the screen they came from, and a column of figures with no
        // period on it is a number somebody will quote at the wrong month.
        $lines[] = $this->row([$report->judul]);
        $lines[] = $this->row([$report->period->label]);
        $lines[] = '';

        $lines[] = $this->row(array_map(fn (ReportColumn $c) => $c->label, $columns));

        foreach ($report->rows as $row) {
            $lines[] = $this->row(array_map(
                fn (ReportColumn $c) => $c->forCsv($row[$c->key] ?? null),
                $columns,
            ));
        }

        if ($report->hasTotals()) {
            $lines[] = $this->row(array_map(
                fn (ReportColumn $c, int $i) => $i === 0
                    ? 'TOTAL'
                    : $c->forCsv($report->totals[$c->key] ?? null),
                $columns,
                array_keys($columns),
            ));
        }

        foreach ($report->catatan as $note) {
            $lines[] = '';
            $lines[] = $this->row([$note]);
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    /** @param  list<string>  $fields */
    private function row(array $fields): string
    {
        return implode(';', array_map(function (string $field): string {
            if ($field === '' || preg_match('/^-?\d+(\.\d+)?$/', $field) === 1) {
                return $field;
            }

            return '"'.str_replace('"', '""', $field).'"';
        }, $fields));
    }
}
