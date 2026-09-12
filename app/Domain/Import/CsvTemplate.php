<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\PriceList\CanonicalColumns;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

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
            TemplateKind::Pengguna => UserColumns::COLUMNS,
            TemplateKind::Piutang => SaldoAwalColumns::PIUTANG,
            TemplateKind::Hutang => SaldoAwalColumns::HUTANG,
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
            TemplateKind::Pengguna => UserColumns::keterangan(),
            TemplateKind::Piutang => SaldoAwalColumns::keteranganPiutang(),
            TemplateKind::Hutang => SaldoAwalColumns::keteranganHutang(),
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

    /**
     * The customer workbook, as the accounting package lays it out.
     *
     * Two sheets, like the package's own template: the data sheet with the
     * ninety-four headings and two example customers, and a legend that
     * says which of those headings this system reads and what each becomes.
     * The heading row is `CompanyWorkbookLayout::JUDUL` itself, so the file
     * a person downloads is the file the importer recognises.
     */
    public function toXlsx(TemplateKind $kind): string
    {
        if ($kind !== TemplateKind::Pelanggan) {
            throw new \InvalidArgumentException('Hanya template pelanggan yang berbentuk workbook.');
        }

        $workbook = new Spreadsheet;

        $data = $workbook->getActiveSheet();
        $data->setTitle(CompanyWorkbookLayout::NAMA_SHEET);
        $data->fromArray([CompanyWorkbookLayout::JUDUL, ...CompanyWorkbookLayout::contoh()], null, 'A1', true);
        $data->getStyle('1:1')->getFont()->setBold(true);
        $data->freezePane('A2');

        $legenda = $workbook->createSheet();
        $legenda->setTitle('Penjelasan Kolom');
        $dibaca = CompanyWorkbookLayout::keterangan();
        $rows = [
            ['Hanya sheet pertama yang diimpor. Kolom yang tidak disebut di bawah ini diterima dan diabaikan.'],
            [],
            ['Nama Kolom', 'Dibaca?', 'Keterangan'],
        ];

        foreach (CompanyWorkbookLayout::JUDUL as $judul) {
            $rows[] = [
                $judul,
                isset($dibaca[trim($judul)]) ? 'Ya' : 'Tidak',
                $dibaca[trim($judul)] ?? '',
            ];
        }

        $legenda->fromArray($rows, null, 'A1', true);
        $legenda->getStyle('3:3')->getFont()->setBold(true);
        $legenda->getColumnDimension('A')->setWidth(42);
        $legenda->getColumnDimension('B')->setWidth(10);
        $legenda->getColumnDimension('C')->setWidth(110);

        $workbook->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'contoh-pelanggan-');

        if ($path === false) {
            throw new \RuntimeException('Tidak bisa menyiapkan template.');
        }

        try {
            IOFactory::createWriter($workbook, 'Xlsx')->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public function namaBerkasXlsx(TemplateKind $kind): string
    {
        return str_replace('.csv', '.xlsx', $this->namaBerkas($kind));
    }

    public function namaBerkas(TemplateKind $kind): string
    {
        return match ($kind) {
            TemplateKind::Harga => 'contoh-impor-harga-barang.csv',
            TemplateKind::Pelanggan => 'contoh-impor-pelanggan.csv',
            TemplateKind::Barang => 'contoh-impor-barang.csv',
            TemplateKind::Pengguna => 'contoh-impor-pengguna.csv',
            TemplateKind::Piutang => 'contoh-saldo-awal-piutang.csv',
            TemplateKind::Hutang => 'contoh-saldo-awal-hutang.csv',
        };
    }

    /** @return list<list<string>> */
    private function contoh(TemplateKind $kind): array
    {
        return match ($kind) {
            TemplateKind::Harga => $this->contohHarga(),
            TemplateKind::Pelanggan => CompanyColumns::contoh(),
            TemplateKind::Barang => ProductColumns::contoh(),
            TemplateKind::Pengguna => UserColumns::contoh(),
            TemplateKind::Piutang => SaldoAwalColumns::contohPiutang(),
            TemplateKind::Hutang => SaldoAwalColumns::contohHutang(),
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
