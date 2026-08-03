<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

/**
 * The canonical price list format.
 *
 * The export format *is* the import format: same columns, same order. The
 * routine update is export → edit the HARGA column → re-import, and that only
 * works if nothing ever reorders or renames these.
 */
final class CanonicalColumns
{
    /** @var list<string> */
    public const COLUMNS = [
        'KODE',
        'MERK',
        'KATEGORI',
        'TIPE_PRODUK',
        'MOBIL',
        'PART_NUMBER',
        'DESCRIPTION',
        'QTY_PER_CTN',
        'SATUAN_DASAR',
        'HARGA',
        'AKTIF',
        'CATATAN',
    ];

    /** Column name → zero-based position. */
    public static function index(string $column): int
    {
        $position = array_search($column, self::COLUMNS, true);

        if ($position === false) {
            throw new \InvalidArgumentException("Unknown canonical column: {$column}");
        }

        return $position;
    }

    public static function count(): int
    {
        return count(self::COLUMNS);
    }
}
