<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * The item import format: the catalogue's canonical columns, without HARGA.
 *
 * The one deliberate difference from `CanonicalColumns`, and the reason this
 * is a separate list rather than a reuse. That format is the price list's,
 * where the export *is* the import and HARGA is the point. This one builds
 * the catalogue — what a SKU **is** — and a price arriving through it would
 * be a price nobody published, which the pricing invariant forbids: prices
 * move by publishing a new `price_list_version`, never by an update.
 *
 * So a file with a HARGA column is refused rather than ignored, in
 * `ProductImporter`. Silently dropping the column would leave somebody
 * believing they had set 400 prices.
 *
 * Everything else follows the price list's column names exactly, because the
 * supplier workbook people copy from uses them and a second vocabulary for
 * the same fields is how a KODE ends up in a MERK column.
 */
final class ProductColumns
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
        'AKTIF',
        'CATATAN',
    ];

    /**
     * What each column is for, shown beside the download.
     *
     * Same wording as the catalogue form's own labels, so somebody who has
     * used one recognises the other.
     *
     * @return array<string, string>
     */
    public static function keterangan(): array
    {
        $merk = implode(', ', config('pricelist.known_brands', []));
        $kategori = implode(', ', config('pricelist.known_categories', []));

        return [
            'KODE' => 'Wajib. Kode barang, unik — satu baris satu kode. Kode yang sudah ada akan memperbarui barang itu.',
            'MERK' => "Wajib. Salah satu dari: {$merk}.",
            'KATEGORI' => "Wajib. Salah satu dari: {$kategori}.",
            'TIPE_PRODUK' => 'Jenis barangnya, mis. Master rem.',
            'MOBIL' => 'Mobil yang cocok.',
            'PART_NUMBER' => 'Nomor part pabrikan.',
            'DESCRIPTION' => 'Nama barang seperti yang dibaca orang gudang.',
            'QTY_PER_CTN' => 'Isi per dus. Kosong dianggap 1.',
            'SATUAN_DASAR' => 'PCS atau SET. Ini yang dipakai buku stok, jadi tidak bisa diubah setelah barang punya riwayat.',
            'AKTIF' => 'Y atau N. Kosong dianggap Y.',
            'CATATAN' => 'Catatan bebas.',
            // Not a column — said here because it is the first thing somebody
            // copying a supplier price list will look for.
            '(HARGA)' => 'Tidak ada di sini. Harga hanya berubah lewat Impor harga & barang, yang menerbitkan versi daftar harga baru.',
        ];
    }

    /**
     * Two SKUs in brands the business carries, one measured in SET — the case
     * somebody gets wrong when they assume everything is counted in pieces.
     *
     * @return list<list<string>>
     */
    public static function contoh(): array
    {
        return [
            ['YH-1001', 'YUHOLI', 'HYDRAULIC PART', 'Master rem', 'Avanza', 'MC-1001',
                'Master rem depan', '10', 'PCS', 'Y', ''],
            ['OS-2001', 'OSBORN', 'SUSPENSION PART', 'Shock absorber', 'Innova', 'SA-2001',
                'Shock absorber depan (sepasang)', '4', 'SET', 'Y', 'Dijual per set'],
        ];
    }
}
