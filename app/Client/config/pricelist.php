<?php

/*
|--------------------------------------------------------------------------
| The supplier's price list
|--------------------------------------------------------------------------
|
| How the raw workbook is read (columns by position, since its headers lie),
| what a header or a title row looks like, the brands and categories the
| company carries, and the limits that turn a cell into a blocker.
|
*/

return [

    // The supplier sheet's columns, zero-based; an extra PART NUMBER in column 7 is ignored.
    'supplier_layout' => [
        'mobil' => 0,
        'part_number' => 1,
        'description' => 2,
        'qty_per_ctn' => 3,
        'kode' => 4,
        'harga' => 5,
        'merk' => 6,
    ],

    // A row is cut to this many cells before it is read (one sheet carries 183 phantom columns).
    'max_columns' => 24,

    // A row holding at least `header_token_threshold` cells equal to one of these is a header, wherever it sits.
    'header_tokens' => [
        'kode', 'no', 'merk', 'merek', 'kategori', 'tipe', 'type', 'mobil', 'part number', 'partnumber', 'part no',
        'description', 'keterangan', 'qty/ctn', 'qty per ctn', 'qty', 'isi', 'satuan', 'harga', 'price', 'aktif', 'catatan',
    ],
    'header_token_threshold' => 3,

    'known_brands' => ['YUHOLI', 'OSBORN', 'ASTRO', 'STAVO', 'STAVIX', 'SERVO', 'BDAX'],

    'known_categories' => ['HYDRAULIC PART', 'SUSPENSION PART', 'ELECTRIC PART', 'BEARING PART'],

    // A one-cell row matching one of these is the sheet's banner, not a category or a product type.
    'title_ignore_patterns' => [
        '/^PRICE\s+LIST\b/i',
        '/^\*?\s*HARGA\s+SEWAKTU\b/i',
    ],

    'base_units' => ['PCS', 'SET'],

    // A carton holding more than this is a blocker: the cell held something else (dimensions, say).
    'max_qty_per_ctn' => 1000,

    // The safety brake, in basis points: the share of prices that may change, and the largest single move.
    'brake' => [
        'max_changed_share_bps' => 2_000,
        'max_single_move_bps' => 5_000,
    ],

    // Where uploaded files live, on the local disk, forever.
    'directory' => 'price-lists',

];
