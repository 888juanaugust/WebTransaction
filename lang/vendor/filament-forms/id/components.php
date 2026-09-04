<?php

declare(strict_types=1);

/*
 * Keys missing from Filament's bundled Indonesian translation. See
 * lang/vendor/filament-panels/id/layout.php for why these files hold only the
 * gaps rather than a full copy.
 */

return [

    'color_picker' => [
        'panel_label' => 'Pemilih warna',
    ],

    'date_time_picker' => [
        'month_select' => ['label' => 'Bulan'],
        'year_input' => ['label' => 'Tahun'],
        'hour_input' => ['label' => 'Jam'],
        'minute_input' => ['label' => 'Menit'],
        'second_input' => ['label' => 'Detik'],
    ],

    'file_upload' => [
        'actions' => [
            'download' => ['label' => 'Unduh'],
            'open' => ['label' => 'Buka di tab baru'],
        ],
        'editor' => ['label' => 'Editor gambar'],
    ],

    'key_value' => [
        'columns' => [
            'actions' => ['label' => 'Aksi'],
            'reorder' => ['label' => 'Ubah urutan'],
        ],
    ],

    'repeater' => [
        'columns' => [
            'actions' => ['label' => 'Aksi'],
            'reorder' => ['label' => 'Ubah urutan'],
        ],
    ],

    /*
     * Added by Filament 4.12.8 (the MFA security release). The coverage test
     * catches new upstream strings on every upgrade — without these three a
     * select renders its raw key, which reads as a broken screen.
     */
    'select' => [
        'actions' => [
            'clear' => ['label' => 'Kosongkan pilihan'],
            'remove_option' => ['label' => 'Hapus :label'],
        ],
        'search_label' => 'Cari',
    ],

    'rich_editor' => [
        'toolbar' => ['label' => 'Bilah alat editor'],
    ],

    'tags_input' => [
        'tag_added' => 'Ditambahkan: :tag',
        'tag_removed' => 'Dihapus: :tag',
    ],

];
