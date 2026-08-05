<?php

declare(strict_types=1);

/*
 * Filament's bundled Indonesian translation is missing these keys, so the panel
 * renders the raw key — "filament-panels::layout.skip_to_content.label" — where
 * the text should be. Most are labels only a screen reader reads, which is
 * exactly why nobody noticed.
 *
 * Laravel merges vendor overrides recursively, so only the missing keys belong
 * here. Do not copy the rest of the upstream file in: it would silently freeze
 * the translations we did not write.
 */

return [

    'skip_to_content' => [
        'label' => 'Lewati ke konten',
    ],

    'actions' => [

        'open_database_notifications' => [
            // Indonesian does not inflect for number, but Laravel still needs
            // the ranges to pick a branch.
            'label_with_unread_count' => '{1} Notifikasi, :count belum dibaca|[2,*] Notifikasi, :count belum dibaca',
        ],

        'theme_switcher' => [
            'label' => 'Tema',
        ],

    ],

    'navigation' => [
        'label' => 'Navigasi samping',
    ],

    'topbar' => [
        'label' => 'Bilah atas',
    ],

];
