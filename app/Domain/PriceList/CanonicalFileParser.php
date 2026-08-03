<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser for a file in our own canonical format — the one the exporter writes.
 *
 * This is the routine path: export → edit the HARGA column → re-import. The
 * columns are fixed and positional, so an operator who inserts a column breaks
 * the shape rather than silently shifting every price by one field; the header
 * check catches that at upload.
 */
class CanonicalFileParser
{
    public function __construct(
        private readonly CellReader $cells = new CellReader,
    ) {}

    /**
     * @return Generator<ParsedRow>
     */
    public function parse(string $path): Generator
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();

        $sheetName = $sheet->getTitle();
        $seenHeader = false;

        foreach ($sheet->toArray(null, true, false, false) as $index => $cells) {
            $rowNumber = $index + 1;
            $cells = array_slice($cells, 0, CanonicalColumns::count());

            if (! $seenHeader) {
                $this->assertHeader($cells);
                $seenHeader = true;

                continue;
            }

            if ($this->isBlank($cells)) {
                continue;
            }

            yield $this->buildRow($cells, $sheetName, $rowNumber);
        }
    }

    /**
     * The canonical header must be exactly right. A file whose first row does
     * not match is not this format, and guessing at it is how a HARGA column
     * ends up being read as QTY_PER_CTN.
     *
     * @param  list<string|null>  $cells
     */
    private function assertHeader(array $cells): void
    {
        $actual = array_map(fn ($c) => strtoupper(trim((string) $c)), $cells);

        if ($actual !== CanonicalColumns::COLUMNS) {
            throw new \RuntimeException(
                'Header tidak sesuai format baku. Diharapkan: '
                .implode(' | ', CanonicalColumns::COLUMNS)
            );
        }
    }

    /**
     * @param  list<string|null>  $cells
     */
    private function buildRow(array $cells, string $sheetName, int $rowNumber): ParsedRow
    {
        $at = fn (string $column) => $cells[CanonicalColumns::index($column)] ?? null;

        $row = new ParsedRow(
            merk: $this->cells->upper($at('MERK')),
            kategori: $this->cells->upper($at('KATEGORI')),
            tipeProduk: $this->cells->text($at('TIPE_PRODUK')),
            mobil: $this->cells->text($at('MOBIL')),
            partNumber: $this->cells->text($at('PART_NUMBER')),
            description: $this->cells->text($at('DESCRIPTION')),
            satuanDasar: $this->cells->upper($at('SATUAN_DASAR')) ?? 'PCS',
            aktif: $this->cells->parseAktif($at('AKTIF')),
            catatan: $this->cells->text($at('CATATAN')),
            sheetName: $sheetName,
            sourceRowNumber: $rowNumber,
            raw: array_values(array_map(fn ($c) => $c === null ? null : (string) $c, $cells)),
        );

        $kodeParts = $this->cells->splitKode($this->cells->text($at('KODE')));

        if ($kodeParts === []) {
            $row->blocker('kode_kosong', 'KODE kosong.');
        } elseif (count($kodeParts) > 1) {
            $row->kode = $kodeParts[0];
            $row->blocker('kode_ganda', 'KODE berisi lebih dari satu SKU: '.implode(' / ', $kodeParts).'.');
        } else {
            $row->kode = $kodeParts[0];
        }

        if ($row->merk === null) {
            $row->blocker('merk_kosong', 'MERK kosong.');
        }

        if ($row->kategori === null) {
            $row->blocker('kategori_tidak_terdeteksi', 'KATEGORI kosong.');
        }

        $qty = $this->cells->splitQtyPerCtn($this->cells->text($at('QTY_PER_CTN')));

        if ($qty === []) {
            $row->qtyPerCtn = 1;
            $row->note('qty_ctn_kosong', 'QTY/CTN kosong, dipakai 1.');
        } elseif (count($qty) > 1) {
            $row->qtyPerCtn = $qty[0];
            $row->blocker('qty_ctn_ganda', 'QTY/CTN berisi dua nilai: '.implode(' / ', $qty).'.');
        } else {
            $row->qtyPerCtn = $qty[0];
        }

        $harga = $this->cells->parseHarga($at('HARGA'));

        if ($harga === null) {
            $row->blocker('harga_tidak_valid', 'HARGA bukan angka: '.trim((string) $at('HARGA')).'.');
        } elseif ($harga <= 0) {
            $row->blocker('harga_nol', "HARGA tidak masuk akal: {$harga}.");
        } else {
            $row->harga = $harga;
        }

        if (! in_array($row->satuanDasar, (array) config('pricelist.base_units'), true)) {
            $row->note('satuan_tidak_dikenal', "SATUAN_DASAR tidak dikenal: {$row->satuanDasar}, dipakai PCS.");
            $row->satuanDasar = 'PCS';
        }

        return $row;
    }

    /** @param list<string|null> $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
