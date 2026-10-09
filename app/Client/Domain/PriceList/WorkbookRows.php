<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/** Every row of every sheet of a workbook (or of a CSV, as one sheet), as [sheet name, row number, cells]. */
final class WorkbookRows
{
    /** @return Generator<int, array{0: ?string, 1: int, 2: list<mixed>}> */
    public static function read(string $path): Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException(__('The file could not be read.'));
        }
        if (self::isWorkbook($path)) {
            $reader = new XlsxReader;
            $reader->open($path);
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $n = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        $n++;
                        yield [$sheet->getName(), $n, array_map(fn ($cell) => $cell instanceof \DateTimeInterface ? $cell->format('Y-m-d') : $cell, $row->toArray())];
                    }
                }
            } finally {
                $reader->close();
            }

            return;
        }

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = self::delimiter($path);
        $reader = new CsvReader($options);
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    yield [null, $n, $row->toArray()];
                }
            }
        } finally {
            $reader->close();
        }
    }

    /** By extension, else by the zip signature every .xlsx starts with. */
    public static function isWorkbook(string $path): bool
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

    /** Comma, semicolon or tab: whichever the first lines hold most. */
    private static function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException(__('The file could not be read.'));
        }
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        for ($i = 0; $i < 10 && ($line = fgets($handle)) !== false; $i++) {
            foreach ($counts as $char => $n) {
                $counts[$char] = $n + substr_count($line, $char);
            }
        }
        fclose($handle);
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
