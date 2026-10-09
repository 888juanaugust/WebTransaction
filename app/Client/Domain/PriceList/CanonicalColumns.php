<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

/** The columns of the price list as the company keeps it: the export format is the import format. KODE is the key. */
final class CanonicalColumns
{
    public const COLUMNS = ['KODE', 'MERK', 'KATEGORI', 'TIPE_PRODUK', 'MOBIL', 'PART_NUMBER', 'DESCRIPTION', 'QTY_PER_CTN', 'SATUAN_DASAR', 'HARGA', 'AKTIF', 'CATATAN'];

    public static function header(): string
    {
        return implode(' | ', self::COLUMNS);
    }
}
