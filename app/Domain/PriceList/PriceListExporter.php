<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

use App\Models\PriceListItem;
use App\Models\PriceListVersion;
use App\Models\Product;
use Generator;

/**
 * The export format IS the import format — same columns, same order.
 *
 * If you change anything here, change CanonicalFileParser to match, or the
 * routine export → edit HARGA → re-import loop breaks.
 */
class PriceListExporter
{
    /**
     * @return Generator<list<string>> Header row first, then one row per SKU.
     */
    public function rows(PriceListVersion $version): Generator
    {
        yield CanonicalColumns::COLUMNS;

        $products = Product::query()->get()->keyBy('kode');

        $items = PriceListItem::query()
            ->where('version_id', $version->id)
            ->orderBy('kode');

        foreach ($items->cursor() as $item) {
            $product = $products->get($item->kode);

            yield [
                $item->kode,
                $product?->merk ?? '',
                $product?->kategori ?? '',
                $product?->tipe_produk ?? '',
                $product?->mobil ?? '',
                $product?->part_number ?? '',
                $product?->description ?? '',
                (string) $item->qty_per_ctn,
                $product?->satuan_dasar ?? 'PCS',
                (string) $item->harga,
                $item->aktif ? 'Y' : 'N',
                $product?->catatan ?? '',
            ];
        }
    }

    /** Write the version to a CSV at $path and return the path. */
    public function toCsv(PriceListVersion $version, string $path): string
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Tidak bisa menulis ke {$path}.");
        }

        try {
            foreach ($this->rows($version) as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }
}
