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
     | Columns are mapped by POSITION, never by reading header text. Two header
     | rows in the supplier file (around rows 872 and 884) are mislabeled — the
     | text disagrees with the data underneath it — so header text is only ever
     | used to *detect* that a row is a header, never to decide what a column
     | means. Every mapped value is then validated against the shape it should
     | have, and rows that fail validation become blockers.
     |
     | Zero-based column indices.
     */
    'supplier_layout' => [
        'kode' => 0,
        'tipe_produk' => 1,
        'mobil' => 2,
        'part_number' => 3,
        'description' => 4,
        'qty_per_ctn' => 5,
        'harga' => 6,
        'merk' => 7,
    ],

    /*
     | One sheet in the supplier workbook carries ~183 phantom columns of
     | formatting residue. Anything past this width is dropped before parsing.
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
     */
    'known_categories' => ['HYDRAULIC PART', 'SUSPENSION PART', 'ELECTRIC PART', 'BEARING PART'],

    'base_units' => ['PCS', 'SET'],

];
