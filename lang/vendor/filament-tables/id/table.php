<?php

declare(strict_types=1);

/*
 * Keys missing from Filament's bundled Indonesian translation, so a table
 * renders the raw key — "filament-tables::table.result_count" — where the text
 * should be. Laravel merges vendor overrides recursively, so only the missing
 * keys belong here; copying the rest of the upstream file in would silently
 * freeze translations we did not write.
 */

return [

    // Indonesian does not inflect for number, but Laravel still needs the
    // ranges to pick a branch.
    'result_count' => '{0} Tidak ada hasil|{1} :count hasil|[2,*] :count hasil',

    'loading' => 'Memuat...',

    'column_manager' => [
        'actions' => [
            'reorder' => ['label' => 'Ubah urutan kolom'],
        ],
    ],

    'columns' => [

        'icon' => [

            'boolean' => [
                'true' => 'Ya',
                'false' => 'Tidak',
            ],

        ],

    ],

    'actions' => [
        'reorder_record' => ['label' => 'Ubah urutan item :key'],
        'toggle_record_content' => ['label' => 'Buka/tutup item :key'],
    ],

];
