<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

/** Reads what a supplier typed in a cell: codes, carton sizes, prices with either separator convention, yes/no. */
final class CellReader
{
    /** The SKUs a KODE cell holds, split on "/"; more than one is the supplier's shorthand, never ours. */
    public static function splitKode(mixed $raw): array
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('#\s*/\s*#', $text) ?: []), fn (string $p) => $p !== ''));
    }

    /** The numbers a QTY/CTN cell holds: "18 / 10" is two, "26-22-55" three (dimensions), "FULL KIT" none. */
    public static function splitQtyPerCtn(mixed $raw): array
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return [];
        }
        $values = [];
        foreach (preg_split('#\s*[/,;\-x×]\s*#iu', $text) ?: [] as $part) {
            $digits = preg_replace('/[^0-9]/', '', $part) ?? '';
            if ($digits !== '' && (int) $digits > 0) {
                $values[] = (int) $digits;
            }
        }

        return $values;
    }

    /** A price in whole rupiah: "Rp 1.250.000", "1,250,000", 875000, 1250000.50 all read; text does not. */
    public static function parseHarga(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_float($raw)) {
            return (int) round($raw);
        }
        $v = preg_replace('/[^0-9.,\-]/', '', (string) $raw) ?? '';
        if ($v === '' || $v === '-') {
            return null;
        }
        $dot = strrpos($v, '.');
        $comma = strrpos($v, ',');
        if ($dot !== false && $comma !== false) {
            [$thousands, $decimal] = $dot > $comma ? [',', '.'] : ['.', ','];
            $v = str_replace($thousands, '', $v);
            $v = str_replace($decimal, '.', $v);
        } elseif ($comma !== false) {
            $v = strlen($v) - $comma === 4 ? str_replace(',', '', $v) : str_replace(',', '.', $v);
        } elseif ($dot !== false) {
            $v = strlen($v) - $dot === 4 ? str_replace('.', '', $v) : $v;
        }
        if (! is_numeric($v)) {
            return null;
        }

        return (int) round((float) $v);
    }

    /** Trimmed, line breaks and runs of blanks flattened to one space; empty is null. */
    public static function text(mixed $raw): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($raw ?? '')));

        return $text === '' ? null : $text;
    }

    public static function upper(mixed $raw): ?string
    {
        $text = self::text($raw);

        return $text === null ? null : strtoupper($text);
    }

    /** Y/N as people type it; blank is the default. */
    public static function parseAktif(mixed $raw, bool $default = true): bool
    {
        $text = strtolower(trim((string) ($raw ?? '')));
        if ($text === '') {
            return $default;
        }

        return in_array($text, ['1', 'y', 'ya', 'yes', 'true', 'aktif', 'a'], true);
    }

    public static function isStandardKode(string $kode): bool
    {
        return preg_match('/^[A-Z0-9][A-Z0-9\-\.]{1,58}$/i', $kode) === 1;
    }
}
