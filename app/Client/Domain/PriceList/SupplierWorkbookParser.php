<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

use Generator;

/**
 * Reads the supplier's raw workbook: every sheet, columns by position (its
 * headers lie), category and product type from the nearest title row above,
 * the brand from the MERK column and never the sheet name. Each row comes
 * back with the issues that block it or annotate it.
 */
final class SupplierWorkbookParser
{
    /** @return Generator<int, ParsedRow> */
    public function parse(string $path): Generator
    {
        $max = (int) config('pricelist.max_columns', 24);
        $currentSheet = null;
        $category = null;
        $tipe = null;
        foreach (WorkbookRows::read($path) as [$sheet, $rowNumber, $cells]) {
            if ($sheet !== $currentSheet) {
                $currentSheet = $sheet;
                $category = null;
                $tipe = null;
            }
            $cells = array_slice(array_values($cells), 0, $max);
            $kind = RowClassifier::classify($cells);
            if ($kind === RowClassifier::BLANK || $kind === RowClassifier::HEADER) {
                continue;
            }
            if ($kind === RowClassifier::TITLE) {
                $title = self::firstFilled($cells);
                switch (RowClassifier::titleKind($title)) {
                    case RowClassifier::TITLE_CATEGORY:
                        $category = RowClassifier::categoryFromTitle($title);
                        $tipe = null; // a new category clears the carried product type
                        break;
                    case RowClassifier::TITLE_TIPE:
                        $tipe = RowClassifier::tipeFromTitle($title);
                        break;
                }

                continue;
            }

            yield $this->buildRow($sheet, $rowNumber, $cells, $category, $tipe);
        }
    }

    /** @param  list<mixed>  $cells */
    private function buildRow(?string $sheet, int $rowNumber, array $cells, ?string $category, ?string $tipe): ParsedRow
    {
        $layout = (array) config('pricelist.supplier_layout');
        $at = fn (string $field) => $cells[$layout[$field] ?? -1] ?? null;

        $row = new ParsedRow(
            sheet: $sheet,
            rowNumber: $rowNumber,
            raw: array_map(fn ($c) => $c === null ? null : (string) $c, $cells),
            tipeProduk: $tipe,
            mobil: CellReader::text($at('mobil')),
            partNumber: CellReader::text($at('part_number')),
            description: CellReader::text($at('description')),
        );
        $this->readKode($row, $at('kode'));
        $this->readMerk($row, $at('merk'));
        $this->readKategori($row, $category);
        $this->readQtyPerCtn($row, $at('qty_per_ctn'));
        $this->readHarga($row, $at('harga'));
        $row->satuanDasar = 'PCS'; // the parser never guesses a base unit

        return $row;
    }

    private function readKode(ParsedRow $row, mixed $raw): void
    {
        $parts = CellReader::splitKode($raw);
        if ($parts === []) {
            $row->blocker('kode_kosong', __('KODE is empty.'));

            return;
        }
        $row->kode = strtoupper($parts[0]);
        if (count($parts) > 1) {
            $row->blocker('kode_ganda', __('KODE holds more than one SKU: :kode. Split it by hand.', ['kode' => implode(' / ', $parts)]));

            return;
        }
        if (! CellReader::isStandardKode($row->kode)) {
            $row->note('kode_tidak_standar', __('KODE is not in the standard form: :kode.', ['kode' => $row->kode]));
        }
    }

    private function readMerk(ParsedRow $row, mixed $raw): void
    {
        $merk = CellReader::upper($raw);
        if ($merk === null) {
            $row->blocker('merk_kosong', __('MERK is empty; the sheet name is never used as the brand.'));

            return;
        }
        $row->merk = $merk;
        if (! in_array($merk, array_map('strtoupper', (array) config('pricelist.known_brands', [])), true)) {
            $row->note('merk_tidak_dikenal', __('Brand outside the list: :merk.', ['merk' => $merk]));
        }
    }

    private function readKategori(ParsedRow $row, ?string $category): void
    {
        if ($category === null) {
            $row->blocker('kategori_tidak_terdeteksi', __('No category detected: no title row above this one.'));

            return;
        }
        $row->kategori = $category;
        if (! in_array($category, array_map('strtoupper', (array) config('pricelist.known_categories', [])), true)) {
            $row->note('kategori_tidak_dikenal', __('Category outside the list: :kategori.', ['kategori' => $category]));
        }
    }

    private function readQtyPerCtn(ParsedRow $row, mixed $raw): void
    {
        $values = CellReader::splitQtyPerCtn($raw);
        $text = CellReader::text($raw);
        if ($values === []) {
            $row->qtyPerCtn = 1;
            if ($text === null) {
                $row->note('qty_ctn_kosong', __('QTY/CTN is empty; 1 is used.'));
            } else {
                $row->note('qty_ctn_bukan_angka', __('QTY/CTN is not a number: ":text". 1 is used — check the base unit (SET, perhaps).', ['text' => $text]));
            }

            return;
        }
        if (count($values) > 1) {
            $row->qtyPerCtn = $values[0];
            $row->blocker('qty_ctn_ganda', __('QTY/CTN holds more than one value: ":text". Pick one.', ['text' => $text]));

            return;
        }
        $max = (int) config('pricelist.max_qty_per_ctn', 1000);
        if ($values[0] > $max) {
            $row->qtyPerCtn = 1;
            $row->blocker('qty_ctn_tidak_masuk_akal', __('QTY/CTN makes no sense: ":text" reads as :n, the limit is :max.', ['text' => $text, 'n' => $values[0], 'max' => $max]));

            return;
        }
        $row->qtyPerCtn = $values[0];
    }

    private function readHarga(ParsedRow $row, mixed $raw): void
    {
        $harga = CellReader::parseHarga($raw);
        if ($harga === null) {
            $row->blocker('harga_tidak_valid', __('HARGA is not a number: :text.', ['text' => trim((string) ($raw ?? ''))]));

            return;
        }
        $row->harga = $harga;
        if ($harga <= 0) {
            $row->blocker('harga_nol', __('HARGA makes no sense: :harga.', ['harga' => $harga]));
        }
    }

    /** @param  list<mixed>  $cells */
    private static function firstFilled(array $cells): string
    {
        foreach ($cells as $cell) {
            $text = trim((string) ($cell ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
