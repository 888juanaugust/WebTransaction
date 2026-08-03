<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

/**
 * Coercions for the specific ways the supplier workbook is untidy.
 *
 * Every method here answers with a value *and* whether it had to guess. The
 * importer turns "had to guess" into either a note or a blocker; nothing here
 * decides on its own to let a bad value through.
 */
class CellReader
{
    /**
     * A KODE cell may hold two or more SKUs separated by "/".
     *
     * Splitting them automatically would invent products that nobody priced,
     * so the caller treats a multi-value cell as a blocker.
     *
     * @return list<string>
     */
    public function splitKode(?string $raw): array
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('#\s*/\s*#', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    /**
     * A QTY/CTN cell may hold two values ("18 / 10"), which means the supplier
     * changed the carton size and left both in. Ambiguous, so: blocker.
     *
     * @return list<int>
     */
    public function splitQtyPerCtn(?string $raw): array
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('#[/,;]+#', $value) ?: [];

        $numbers = [];

        foreach ($parts as $part) {
            $digits = preg_replace('/[^0-9]/', '', $part);

            if ($digits !== '' && (int) $digits > 0) {
                $numbers[] = (int) $digits;
            }
        }

        return $numbers;
    }

    /**
     * Prices arrive as "1.250.000", "1,250,000", "Rp 1.250.000", or a float
     * from the cell itself.
     *
     * Returns null when the cell is not a price at all — the caller makes that
     * a blocker rather than guessing zero.
     */
    public function parseHarga(mixed $raw): ?int
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

        $value = trim((string) $raw);

        // Strip currency and spaces, leaving digits and separators.
        $value = preg_replace('/[^0-9.,\-]/', '', $value) ?? '';

        if ($value === '' || $value === '-') {
            return null;
        }

        // Indonesian files use "." for thousands and "," for decimals; some
        // exports use the opposite. Whichever separator appears last is the
        // decimal one — and rupiah has no meaningful decimals anyway, so once
        // separators are resolved the fraction is dropped.
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            $decimalSep = $lastDot > $lastComma ? '.' : ',';
            $thousandsSep = $decimalSep === '.' ? ',' : '.';
            $value = str_replace($thousandsSep, '', $value);
            $value = str_replace($decimalSep, '.', $value);
        } elseif ($lastComma !== false) {
            // A lone comma with exactly three digits after it is thousands.
            $value = strlen($value) - $lastComma === 4
                ? str_replace(',', '', $value)
                : str_replace(',', '.', $value);
        } elseif ($lastDot !== false) {
            $value = strlen($value) - $lastDot === 4
                ? str_replace('.', '', $value)
                : $value;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    public function text(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));

        return $value === '' ? null : $value;
    }

    public function upper(mixed $raw): ?string
    {
        $value = $this->text($raw);

        return $value === null ? null : strtoupper($value);
    }

    /**
     * AKTIF accepts the several ways a spreadsheet says yes.
     */
    public function parseAktif(mixed $raw, bool $default = true): bool
    {
        $value = strtolower(trim((string) ($raw ?? '')));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'y', 'ya', 'yes', 'true', 'aktif', 'a'], true);
    }

    /**
     * A well-formed KODE is alphanumeric with dashes or dots. Anything else
     * still imports, but gets annotated.
     */
    public function isStandardKode(string $kode): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9\-\.]{1,58}$/i', $kode);
    }
}
