<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\PriceList\CanonicalColumns;

/**
 * The example file, generated from the format itself.
 *
 * The testers' complaint was not that the importer was broken — it was that
 * nothing told them what to put in the file. A format documented in prose
 * drifts from the parser the first time a column moves; a template generated
 * from the same constant the parser reads cannot.
 *
 * Written with the same delimiter and quoting as the price list export,
 * because for prices the export *is* the import format and a template in a
 * different dialect would teach the wrong thing.
 */
class CsvTemplate
{
    /**
     * @return list<string> the column headings
     */
    public function columns(TemplateKind $kind): array
    {
        return match ($kind) {
            TemplateKind::Harga => CanonicalColumns::COLUMNS,
            TemplateKind::Pelanggan => CompanyColumns::COLUMNS,
            TemplateKind::Barang => ProductColumns::COLUMNS,
        };
    }

    /**
     * @return array<string, string> column → what it is for
     */
    public function keterangan(TemplateKind $kind): array
    {
        return match ($kind) {
            TemplateKind::Harga => $this->keteranganHarga(),
            TemplateKind::Pelanggan => CompanyColumns::keterangan(),
            TemplateKind::Barang => ProductColumns::keterangan(),
        };
    }

    /** The template as a CSV string: heading row, then the examples. */
    public function toCsv(TemplateKind $kind): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Tidak bisa menyiapkan template.');
        }

        try {
            /*
             * A BOM, so Excel in an Indonesian locale opens it as UTF-8 and
             * the example rows do not arrive with mangled accents — the
             * same reason ReportCsv writes one.
             */
            fwrite($handle, "\u{FEFF}");

            fputcsv($handle, $this->columns($kind), ',', '"', '\\');

            foreach ($this->contoh($kind) as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }

            rewind($handle);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    public function namaBerkas(TemplateKind $kind): string
    {
        return match ($kind) {
            TemplateKind::Harga => 'contoh-impor-harga-barang.csv',
            TemplateKind::Pelanggan => 'contoh-impor-pelanggan.csv',
            TemplateKind::Barang => 'contoh-impor-barang.csv',
        };
    }

    /** @return list<list<string>> */
    private function contoh(TemplateKind $kind): array
    {
        return match ($kind) {
            TemplateKind::Harga => $this->contohHarga(),
            TemplateKind::Pelanggan => CompanyColumns::contoh(),
            TemplateKind::Barang => ProductColumns::contoh(),
        };
    }

    /**
     * Two SKUs in the brands the business actually carries, one of them
     * measured in SET rather than PCS — the case somebody gets wrong when
     * they assume everything is counted in pieces.
     *
     * @return list<list<string>>
     */
    private function contohHarga(): array
    {
        return [
            ['YH-1001', 'YUHOLI', 'HYDRAULIC PART', 'Master rem', 'Avanza', 'MC-1001',
                'Master rem depan', '10', 'PCS', '375000', 'Y', ''],
            ['OS-2001', 'OSBORN', 'SUSPENSION PART', 'Shock absorber', 'Innova', 'SA-2001',
                'Shock absorber depan (sepasang)', '4', 'SET', '799500', 'Y', 'Dijual per set'],
        ];
    }

    /** @return array<string, string> */
    private function keteranganHarga(): array
    {
        return [
            'KODE' => 'Wajib. Kode barang, unik — satu baris satu kode. Kode yang sudah ada akan diperbarui.',
            'MERK' => 'Wajib. YUHOLI, OSBORN, ASTRO, STAVO, STAVIX, SERVO, atau BDAX.',
            'KATEGORI' => 'HYDRAULIC PART, SUSPENSION PART, ELECTRIC PART, atau BEARING PART.',
            'TIPE_PRODUK' => 'Jenis barangnya, mis. Master rem.',
            'MOBIL' => 'Mobil yang cocok.',
            'PART_NUMBER' => 'Nomor part pabrikan.',
            'DESCRIPTION' => 'Nama barang seperti yang dibaca orang gudang.',
            'QTY_PER_CTN' => 'Isi per karton. Kosong dianggap 1.',
            'SATUAN_DASAR' => 'PCS atau SET.',
            'HARGA' => 'Angka rupiah tanpa titik, mis. 375000.',
            'AKTIF' => 'Y atau N.',
            'CATATAN' => 'Catatan bebas.',
        ];
    }
}
