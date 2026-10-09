<?php

declare(strict_types=1);

namespace App\Client\Domain\PriceList;

use Generator;
use RuntimeException;

/** Reads a file in the company's own format (what the export writes): the first sheet, the twelve columns, in order. */
final class CanonicalFileParser
{
    /** @return Generator<int, ParsedRow> */
    public function parse(string $path): Generator
    {
        $headerChecked = false;
        $firstSheet = null;
        foreach (WorkbookRows::read($path) as [$sheet, $rowNumber, $cells]) {
            if ($firstSheet === null) {
                $firstSheet = $sheet ?? '';
            } elseif (($sheet ?? '') !== $firstSheet) {
                break; // the first sheet only
            }
            $cells = array_slice(array_values($cells), 0, count(CanonicalColumns::COLUMNS));
            if (! $headerChecked) {
                $header = array_map(fn ($c) => strtoupper(trim((string) ($c ?? ''))), $cells);
                if ($header !== CanonicalColumns::COLUMNS) {
                    throw new RuntimeException(__('The header is not the standard format. Expected: :columns', ['columns' => CanonicalColumns::header()]));
                }
                $headerChecked = true;

                continue;
            }
            if (array_filter(array_map(fn ($c) => trim((string) ($c ?? '')), $cells), fn (string $c) => $c !== '') === []) {
                continue;
            }

            yield $this->buildRow($sheet, $rowNumber, array_pad($cells, count(CanonicalColumns::COLUMNS), null));
        }
    }

    /** @param  list<mixed>  $cells */
    private function buildRow(?string $sheet, int $rowNumber, array $cells): ParsedRow
    {
        [$kode, $merk, $kategori, $tipe, $mobil, $partNumber, $description, $qty, $satuan, $harga, $aktif, $catatan] = $cells;
        $row = new ParsedRow(
            sheet: $sheet,
            rowNumber: $rowNumber,
            raw: array_map(fn ($c) => $c === null ? null : (string) $c, $cells),
            merk: CellReader::upper($merk),
            kategori: CellReader::upper($kategori),
            tipeProduk: CellReader::text($tipe),
            mobil: CellReader::text($mobil),
            partNumber: CellReader::text($partNumber),
            description: CellReader::text($description),
            satuanDasar: CellReader::upper($satuan) ?? 'PCS',
            aktif: CellReader::parseAktif($aktif),
            catatan: CellReader::text($catatan),
        );

        $parts = CellReader::splitKode($kode);
        if ($parts === []) {
            $row->blocker('kode_kosong', __('KODE is empty.'));
        } else {
            $row->kode = strtoupper($parts[0]);
            if (count($parts) > 1) {
                $row->blocker('kode_ganda', __('KODE holds more than one SKU: :kode.', ['kode' => implode(' / ', $parts)]));
            }
        }
        if ($row->merk === null) {
            $row->blocker('merk_kosong', __('MERK is empty.'));
        }
        if ($row->kategori === null) {
            $row->blocker('kategori_tidak_terdeteksi', __('KATEGORI is empty.'));
        }

        $values = CellReader::splitQtyPerCtn($qty);
        if ($values === []) {
            $row->qtyPerCtn = 1;
            $row->note('qty_ctn_kosong', __('QTY/CTN is empty; 1 is used.'));
        } elseif (count($values) > 1) {
            $row->qtyPerCtn = $values[0];
            $row->blocker('qty_ctn_ganda', __('QTY/CTN holds two values: :text.', ['text' => CellReader::text($qty)]));
        } else {
            $row->qtyPerCtn = $values[0];
        }

        $price = CellReader::parseHarga($harga);
        if ($price === null) {
            $row->blocker('harga_tidak_valid', __('HARGA is not a number: :text.', ['text' => trim((string) ($harga ?? ''))]));
        } else {
            $row->harga = $price;
            if ($price <= 0) {
                $row->blocker('harga_nol', __('HARGA makes no sense: :harga.', ['harga' => $price]));
            }
        }

        if (! in_array($row->satuanDasar, array_map('strtoupper', (array) config('pricelist.base_units', ['PCS', 'SET'])), true)) {
            $row->note('satuan_tidak_dikenal', __('SATUAN_DASAR unknown: :satuan; PCS is used.', ['satuan' => $row->satuanDasar]));
            $row->satuanDasar = 'PCS';
        }

        return $row;
    }
}
