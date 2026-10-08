<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use Carbon\CarbonImmutable;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * The rows of the first sheet of an Excel workbook (.xlsx), or of a CSV file
 * whose separator (comma, semicolon or tab) is read from its first lines.
 * Dates in a workbook come back as Y-m-d; everything else as written.
 */
final class SpreadsheetReader
{
    /** @return list<array<int, mixed>> */
    public static function rows(string $path): array
    {
        return self::isWorkbook($path) ? self::workbook($path) : self::csv($path);
    }

    /** By extension, else by the zip signature every .xlsx starts with. */
    private static function isWorkbook(string $path): bool
    {
        if (str_ends_with(strtolower($path), '.xlsx')) {
            return true;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 4);
        fclose($handle);

        return $head === "PK\x03\x04";
    }

    /** @return list<array<int, mixed>> */
    private static function workbook(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($cell) => $cell instanceof \DateTimeInterface ? CarbonImmutable::instance($cell)->toDateString() : $cell, $row->toArray());
                }
                break; // the first sheet
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    /** @return list<array<int, mixed>> */
    private static function csv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException(__('The file could not be read.'));
        }
        try {
            $delimiter = self::delimiter($handle);
            $rows = [];
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if ($cells !== [null]) {
                    $rows[] = $cells;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /** @param  resource  $handle */
    private static function delimiter($handle): string
    {
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        for ($i = 0; $i < 10 && ($line = fgets($handle)) !== false; $i++) {
            foreach ($counts as $char => $n) {
                $counts[$char] = $n + substr_count($line, $char);
            }
        }
        rewind($handle);
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
