<?php

declare(strict_types=1);

/*
 * Filament's bundled Indonesian translation is missing the boolean icon
 * labels, so a boolean column renders the raw translation key as its label.
 * Laravel merges vendor overrides recursively, so only the missing keys
 * belong here — do not copy the rest of the upstream file in.
 */

return [

    'columns' => [

        'icon' => [

            'boolean' => [
                'true' => 'Ya',
                'false' => 'Tidak',
            ],

        ],

    ],

];
