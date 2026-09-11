<?php

declare(strict_types=1);

namespace App\Domain\Import;

use DomainException;
use Illuminate\Support\Carbon;

/**
 * The part of every CSV importer that is about CSV rather than about what is
 * being imported: lines, the header, cells by column name, the delimiter,
 * blank rows, and the two ways of reading a number.
 *
 * Extracted for the third, fourth and fifth importers. The first two
 * (`CompanyImporter`, `ProductImporter`) carry their own copies and are left
 * as they are — they are covered by their own tests and a refactor for
 * symmetry's sake is a change with no user in it. New importers use this.
 */
trait ReadsCsv
{
    /**
     * @param  list<string>  $wajib  columns the header must carry
     * @return array<string, int> column → position
     */
    private function header(string $line, array $wajib): array
    {
        $map = [];

        foreach (str_getcsv($line, $this->delimiter($line), '"', '\\') as $position => $name) {
            $name = strtoupper(trim((string) $name));
            $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;

            if ($name !== '') {
                $map[$name] = $position;
            }
        }

        foreach ($wajib as $kolom) {
            if (! array_key_exists($kolom, $map)) {
                throw new DomainException(
                    "Baris judul tidak punya kolom {$kolom}. Unduh contoh CSV dari layar ini "
                    .'dan pakai baris judulnya apa adanya.'
                );
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $header
     * @return array<string, string>
     */
    private function cells(string $line, array $header): array
    {
        $cells = str_getcsv($line, $this->delimiter($line), '"', '\\');
        $out = [];

        foreach ($header as $name => $position) {
            $out[$name] = (string) ($cells[$position] ?? '');
        }

        return $out;
    }

    /** @return list<string> */
    private function lines(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        return array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $contents) ?: [],
            fn (string $line) => trim($line) !== '',
        ));
    }

    /**
     * Comma or semicolon, whichever the line actually uses — Excel in an
     * Indonesian locale saves semicolons.
     */
    private function delimiter(string $line): string
    {
        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    private function blank(string $line): bool
    {
        return trim(str_replace([',', ';', '"'], '', $line)) === '';
    }

    /** @param array<string, string> $cells */
    private function teks(array $cells, string $column): string
    {
        return trim((string) ($cells[$column] ?? ''));
    }

    /**
     * Rupiah as people type it: "12.500.000", "12500000", "Rp 12.500.000".
     * Null for blank, false for not-a-number — "not given" and "wrong" are
     * different answers and a cast cannot tell them apart.
     */
    private function rupiah(string $raw): int|false|null
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        $bersih = preg_replace('/^rp\.?\s*/i', '', $raw) ?? $raw;
        $bersih = str_replace(['.', ',', ' '], '', $bersih);

        return ctype_digit($bersih) ? (int) $bersih : false;
    }

    /**
     * A date as a spreadsheet writes it: 2026-09-01, 01/09/2026, 1-9-2026.
     * Day first for the slash and dash forms, because this is Indonesia and
     * the ISO form is the only one where the order is not a guess.
     */
    private function tanggal(string $raw): ?Carbon
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $raw);

            if ($parsed !== false && $parsed->format($format) === $raw) {
                return Carbon::instance($parsed);
            }
        }

        return null;
    }
}
