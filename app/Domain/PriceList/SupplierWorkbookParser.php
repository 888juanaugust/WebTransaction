<?php

declare(strict_types=1);

namespace App\Domain\PriceList;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser for the raw supplier workbook (PL_JAVA_IMPORT.xlsx and its successors).
 *
 * The file is messy in known ways, and each one is handled deliberately:
 *
 *   · Sheet names do not match brands — the MERK column is authoritative and
 *     the sheet name is never used to infer a brand. The sheet called
 *     "STAVO STAVIX ASTRO" also carries every BDAX and SERVO row in the file.
 *   · 56 repeated header rows scattered mid-file — detected by shape and
 *     skipped wherever they appear.
 *   · Neither KATEGORI nor TIPE_PRODUK is a column. Both are title-only rows,
 *     stacked two deep, and the parser carries each forward independently.
 *   · Two header rows are mislabeled (872 and 884) — so columns are mapped by
 *     POSITION and then validated, never by reading header text.
 *   · 183 phantom columns on one sheet — trimmed to a sane width first.
 *   · KODE cells holding 2+ SKUs split by "/" — blocker, not an auto-split.
 *   · QTY/CTN cells holding two values ("18 / 10") — blocker.
 *   · 724 blank QTY/CTN — defaulted to 1 with a note in CATATAN.
 *   · Line breaks inside cells — normalised to single spaces.
 *   · No effective date anywhere in the file — the operator supplies it at
 *     upload; the parser never invents one.
 *
 * The counts above are from the real file, not estimates. PriceListFixture
 * builds a small workbook reproducing every one of these shapes, which is what
 * the tests run against — the live price list is commercial data and does not
 * belong in git.
 */
class SupplierWorkbookParser
{
    /** @var array<string, int> */
    private array $layout;

    private int $maxColumns;

    public function __construct(
        private readonly CellReader $cells = new CellReader,
        private readonly RowClassifier $classifier = new RowClassifier,
        ?array $layout = null,
        ?int $maxColumns = null,
    ) {
        $this->layout = $layout ?? config('pricelist.supplier_layout');
        $this->maxColumns = $maxColumns ?? (int) config('pricelist.max_columns');
    }

    /**
     * @return Generator<ParsedRow>
     */
    public function parse(string $path): Generator
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $sheetName = $sheet->getTitle();

            // Both are carried forward from the nearest title row above.
            $currentCategory = null;
            $currentTipe = null;

            foreach ($sheet->toArray(null, true, false, false) as $index => $cells) {
                $rowNumber = $index + 1;

                // Drop the phantom columns before anything looks at the row.
                $cells = array_slice($cells, 0, $this->maxColumns);

                switch ($this->classifier->classify($cells)) {
                    case RowClassifier::BLANK:
                    case RowClassifier::HEADER:
                        continue 2;

                    case RowClassifier::TITLE:
                        $title = (string) $this->firstFilled($cells);

                        switch ($this->classifier->titleKind($title)) {
                            case RowClassifier::TITLE_CATEGORY:
                                $currentCategory = $this->classifier->categoryFromTitle($title);
                                // A new category starts a new run of product
                                // types; carrying the last one across would
                                // label brake parts as bearings.
                                $currentTipe = null;
                                break;

                            case RowClassifier::TITLE_TIPE:
                                $currentTipe = $this->classifier->tipeFromTitle($title);
                                break;

                                // A banner names nothing and changes nothing.
                        }

                        continue 2;
                }

                yield $this->buildRow($cells, $sheetName, $rowNumber, $currentCategory, $currentTipe);
            }
        }
    }

    /**
     * @param  list<string|null>  $cells
     */
    private function buildRow(
        array $cells,
        string $sheetName,
        int $rowNumber,
        ?string $category,
        ?string $tipeProduk,
    ): ParsedRow {
        $at = fn (string $field) => $cells[$this->layout[$field]] ?? null;

        $row = new ParsedRow(
            // Not a column in this file — it is the nearest product-type title
            // row above.
            tipeProduk: $tipeProduk,
            mobil: $this->cells->text($at('mobil')),
            partNumber: $this->cells->text($at('part_number')),
            description: $this->cells->text($at('description')),
            sheetName: $sheetName,
            sourceRowNumber: $rowNumber,
            raw: array_values(array_map(fn ($c) => $c === null ? null : (string) $c, $cells)),
        );

        $this->readKode($row, $at('kode'));
        $this->readMerk($row, $at('merk'));
        $this->readKategori($row, $category);
        $this->readQtyPerCtn($row, $at('qty_per_ctn'));
        $this->readHarga($row, $at('harga'));

        // The supplier file has no SATUAN_DASAR column. PCS is the house
        // default; anything that is really sold as a SET is corrected in the
        // catalogue, not guessed here.
        $row->satuanDasar = 'PCS';

        return $row;
    }

    private function readKode(ParsedRow $row, mixed $raw): void
    {
        $parts = $this->cells->splitKode($raw === null ? null : (string) $raw);

        if ($parts === []) {
            $row->blocker('kode_kosong', 'KODE kosong.');

            return;
        }

        if (count($parts) > 1) {
            // One row must mean one KODE. Splitting it here would silently
            // create SKUs at a price nobody quoted for them.
            $row->kode = $parts[0];
            $row->blocker(
                'kode_ganda',
                'KODE berisi lebih dari satu SKU: '.implode(' / ', $parts).'. Pisahkan manual.'
            );

            return;
        }

        $row->kode = $parts[0];

        if (! $this->cells->isStandardKode($row->kode)) {
            $row->note('kode_tidak_standar', "Format KODE tidak standar: {$row->kode}.");
        }
    }

    private function readMerk(ParsedRow $row, mixed $raw): void
    {
        $merk = $this->cells->upper($raw);

        if ($merk === null) {
            // Never fall back to the sheet name — sheet names in this workbook
            // do not correspond to brands.
            $row->blocker('merk_kosong', 'MERK kosong; nama sheet tidak bisa dipakai sebagai merk.');

            return;
        }

        $row->merk = $merk;

        $known = (array) config('pricelist.known_brands');

        if (! in_array($merk, $known, true)) {
            $row->note('merk_tidak_dikenal', "Merk di luar daftar: {$merk}.");
        }
    }

    private function readKategori(ParsedRow $row, ?string $category): void
    {
        if ($category === null) {
            $row->blocker('kategori_tidak_terdeteksi', 'Kategori tidak terdeteksi: tidak ada baris judul di atasnya.');

            return;
        }

        $row->kategori = $category;

        if (! in_array($category, (array) config('pricelist.known_categories'), true)) {
            $row->note('kategori_tidak_dikenal', "Kategori di luar daftar: {$category}.");
        }
    }

    private function readQtyPerCtn(ParsedRow $row, mixed $raw): void
    {
        $values = $this->cells->splitQtyPerCtn($raw === null ? null : (string) $raw);
        $text = $this->cells->text($raw);

        if ($values === []) {
            $row->qtyPerCtn = 1;

            if ($text === null) {
                // 724 rows in the real file are simply blank here.
                $row->note('qty_ctn_kosong', 'QTY/CTN kosong, dipakai 1.');

                return;
            }

            /*
             * The cell is not empty — it just is not a number. In the real file
             * this is 16 rows reading "FULL KIT" or "MINOR KIT": the supplier
             * used the carton column to say what kind of kit the SKU is.
             *
             * Annotating that as "kosong" would be a lie, and would throw away
             * the one place the file says these are kits. They are worth a
             * human's attention for another reason too: a kit is very likely
             * sold as a SET rather than PCS, and unit of measure is modeled,
             * not assumed. This parser will not guess it — see satuanDasar
             * below — so the text is carried through for staff to act on.
             */
            $row->note(
                'qty_ctn_bukan_angka',
                "QTY/CTN bukan angka: \"{$text}\". Dipakai 1 — periksa satuan dasar (mungkin SET)."
            );

            return;
        }

        if (count($values) > 1) {
            $row->qtyPerCtn = $values[0];
            $row->blocker(
                'qty_ctn_ganda',
                "QTY/CTN berisi lebih dari satu nilai: \"{$text}\". Pilih salah satu."
            );

            return;
        }

        /*
         * A backstop for shapes nobody has anticipated.
         *
         * qty_per_ctn is trusted arithmetic everywhere downstream — it converts
         * a dus into base units for the stock ledger — so a wrong value here is
         * not a display bug, it is stock and money. Anything implausibly large
         * is something other than a carton size that landed in the column, and
         * it stops at a human.
         */
        $max = (int) config('pricelist.max_qty_per_ctn');

        if ($values[0] > $max) {
            $row->qtyPerCtn = 1;
            $row->blocker(
                'qty_ctn_tidak_masuk_akal',
                "QTY/CTN tidak masuk akal: \"{$text}\" terbaca {$values[0]}, batas wajar {$max}."
            );

            return;
        }

        $row->qtyPerCtn = $values[0];
    }

    private function readHarga(ParsedRow $row, mixed $raw): void
    {
        $harga = $this->cells->parseHarga($raw);

        if ($harga === null) {
            $row->blocker('harga_tidak_valid', 'HARGA bukan angka: '.trim((string) $raw).'.');

            return;
        }

        if ($harga <= 0) {
            $row->blocker('harga_nol', "HARGA tidak masuk akal: {$harga}.");

            return;
        }

        $row->harga = $harga;
    }

    /** @param list<string|null> $cells */
    private function firstFilled(array $cells): ?string
    {
        foreach ($cells as $cell) {
            $value = trim((string) $cell);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
