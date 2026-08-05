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

    'rich_editor' => [
        'toolbar' => ['label' => 'Bilah alat editor'],
    ],

    'tags_input' => [
        'tag_added' => 'Ditambahkan: :tag',
        'tag_removed' => 'Dihapus: :tag',
    ],

];
