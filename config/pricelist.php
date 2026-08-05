<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Price list import
|--------------------------------------------------------------------------
|
| The raw supplier workbook is messy in specific, known ways. The parser is
| deliberately tolerant, and everything it cannot resolve confidently becomes
| a blocker for a human rather than a guess.
|
*/

return [

    /*
     | Positional column map for the raw supplier workbook.
     |
     | Verified against the real PL_JAVA_IMPORT.xlsx. Every one of the three
     | sheets lays its data out this way:
     |
     |     0        1             2             3         4      5       6
     |     MOBIL  | PART NUMBER | DESCRIPTION | QTY/CTN | KODE | HARGA | MERK
     |
     | (The OSBORN sheet repeats PART NUMBER at column 7. It is ignored —
     | position 1 is the one the other sheets agree on.)
     |
     | Columns are mapped by POSITION, never by reading header text, and the
     | real file shows exactly why. Two of its 56 header rows are mislabeled:
     |
     |   row 872  MOBIL | KODE | DESCRIPTION | PART NUMBER | HARGA | QTY/CTN | MERK
     |   row 884  MOBIL | PART NUMBER | DESCRIPTION | QTY/CTN | KODE | HARGA | HARGA
     |
     | In both cases the data underneath is in the standard order above — it is
     | the header text that is wrong, not the data. A parser that trusted those
     | headers would file part numbers as SKUs and prices as carton sizes for
     | every row beneath them.
     |
     | TIPE_PRODUK is deliberately absent: it is not a column in this file, it
     | is a title row. See title_ignore_patterns below.
     |
     | Zero-based column indices.
     */
    'supplier_layout' => [
        'mobil' => 0,
        'part_number' => 1,
        'description' => 2,
        'qty_per_ctn' => 3,
        'kode' => 4,
        'harga' => 5,
        'merk' => 6,
    ],

    /*
     | One sheet in the supplier workbook is 183 columns wide (through GA) of
     | formatting residue. Only one cell past column 7 holds anything at all —
     | a stray =UPPER() formula — and nothing past column 7 is ever data.
     |
     | Kept generously wider than the 7 columns actually used, so a supplier who
     | adds a column does not silently lose it; the layout above is what decides
     | meaning, and anything past this width is dropped before parsing.
     */
    'max_columns' => 24,

    /*
     | Tokens that mark a row as a repeated header. The supplier file has ~55
     | of them scattered mid-file. Matching is case- and space-insensitive, and
     | a row needs at least `header_token_threshold` hits to count.
     */
    'header_tokens' => [
        'kode', 'no', 'merk', 'merek', 'kategori', 'tipe', 'type', 'mobil',
        'part number', 'partnumber', 'part no', 'description', 'keterangan',
        'qty/ctn', 'qty per ctn', 'qty', 'isi', 'satuan', 'harga', 'price',
        'aktif', 'catatan',
    ],

    'header_token_threshold' => 3,

    /*
     | Brands we carry. Used to validate the MERK column, which is the
     | authoritative source of brand — sheet names do not match brands and must
     | never be used for it.
     */
    'known_brands' => ['YUHOLI', 'OSBORN', 'ASTRO', 'STAVO', 'STAVIX', 'SERVO', 'BDAX'],

    /*
     | Categories are not a column in the supplier file. They appear as
     | title-only rows above the block of products they cover, and the parser
     | carries the nearest one above each row forward.
     |
     | The real file has *two* levels of title row, which is easy to miss:
     |
     |     HYDRAULIC PART                        <- category, one of these four
     |     BRAKE MASTER / BM ASSY / PUSAT        <- tipe produk, 35 distinct
     |     MOBIL | PART NUMBER | ...             <- header
     |     DAIHATSU | 47201-87511 | ...          <- data
     |
     | Only a title matching this list is a category. Everything else is a
     | product type, and must not overwrite the category — treating the two the
     | same gives every row a "category" of BRAKE MASTER and loses the real one.
     */
    'known_categories' => ['HYDRAULIC PART', 'SUSPENSION PART', 'ELECTRIC PART', 'BEARING PART'],

    /*
     | Title rows that are neither a category nor a product type: the workbook's
     | own banner and disclaimer at the top of each sheet.
     |
     | Careful with the leading asterisk — it does not mark a banner. Several
     | genuine product types start with one ("*FRONT WHEEL", "*REAR WHEEL"), so
     | these match on the wording rather than the punctuation.
     */
    'title_ignore_patterns' => [
        '/^PRICE\s+LIST\b/i',
        '/^\*?\s*HARGA\s+SEWAKTU\b/i',
    ],

    'base_units' => ['PCS', 'SET'],

    /*
     | The largest carton quantity that is believable.
     |
     | In the real supplier file the 689 rows with a clean integer here top out
     | at 400. This bound is a backstop, not a business rule: anything above it
     | is not a carton size, it is something else that landed in the column.
     |
     | That happened. Fourteen rows carried dimensions ("26-22-55" for a CV
     | joint in millimetres); with the hyphen not treated as a separator they
     | collapsed to a carton size of 262,255. Nothing downstream would have
     | questioned it — qty_per_ctn is trusted arithmetic, so one dus ordered
     | would have moved a quarter of a million units through the stock ledger.
     |
     | The separator list in CellReader::splitQtyPerCtn now handles that
     | particular shape. This exists to catch the shapes nobody has thought of
     | yet, which is the point of a bound.
     */
    'max_qty_per_ctn' => 1000,

];
