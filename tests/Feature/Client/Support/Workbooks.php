<?php

namespace Tests\Feature\Client\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Builds the workbooks the price list tests feed the parsers: one or more named sheets of rows, in a scratch file. */
trait Workbooks
{
    /** @var list<string> */
    private array $scratchFiles = [];

    /**
     * @param  array<string, list<list<mixed>>>  $sheets  sheet name → rows
     */
    protected function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
        $this->scratchFiles[] = $path;
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;
        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName((string) $name);
            $first = false;
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($v) => $v ?? '', $row)));
            }
        }
        $writer->close();

        return $path;
    }

    /** The supplier's layout: MOBIL, PART NUMBER, DESCRIPTION, QTY/CTN, KODE, HARGA, MERK. */
    protected function supplierHeader(): array
    {
        return ['MOBIL', 'PART NUMBER', 'DESCRIPTION', 'QTY/CTN', 'KODE', 'HARGA', 'MERK'];
    }

    protected function supplierRow(string $kode, int|string|null $harga = 100_000, ?string $merk = 'YUHOLI', int|string|null $qty = 12, string $description = 'Widget', string $mobil = 'AVANZA', string $partNumber = 'PN-1'): array
    {
        return [$mobil, $partNumber, $description, $qty, $kode, $harga, $merk];
    }

    /** A supplier workbook with a banner, a category title and the rows given. */
    protected function supplierWorkbook(array $rows, string $category = 'HYDRAULIC PART', string $sheet = 'YUHOLI'): string
    {
        return $this->workbook([$sheet => [['PRICE LIST 2026'], [$category], $this->supplierHeader(), ...$rows]]);
    }

    protected function canonicalWorkbook(array $rows): string
    {
        return $this->workbook(['Sheet1' => [['KODE', 'MERK', 'KATEGORI', 'TIPE_PRODUK', 'MOBIL', 'PART_NUMBER', 'DESCRIPTION', 'QTY_PER_CTN', 'SATUAN_DASAR', 'HARGA', 'AKTIF', 'CATATAN'], ...$rows]]);
    }

    protected function canonicalRow(string $kode, int|string|null $harga = 100_000, string $aktif = 'Y', string $merk = 'YUHOLI', string $kategori = 'HYDRAULIC PART', int|string|null $qty = 12, string $satuan = 'PCS', string $description = 'Widget'): array
    {
        return [$kode, $merk, $kategori, 'FRONT WHEEL', 'AVANZA', 'PN-1', $description, $qty, $satuan, $harga, $aktif, ''];
    }

    protected function tearDownWorkbooks(): void
    {
        foreach ($this->scratchFiles as $file) {
            @unlink($file);
        }
    }
}
