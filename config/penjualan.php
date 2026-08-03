<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Penjualan
|--------------------------------------------------------------------------
*/

return [

    /*
     | How long a confirmed-but-unpaid order may hold its stock reservation
     | before the scheduled sweep releases it. Default 48 hours.
     */
    'reservation_ttl_minutes' => (int) env('RESERVATION_TTL_MINUTES', 2880),

    /*
     | Safety brake on price list imports. Crossing either of these requires a
     | second confirmation that names the numbers.
     */
    'import_brake' => [
        'max_changed_share_bps' => 2_000,   // >20% of prices changed
        'max_single_move_bps' => 5_000,     // any one price moving >50%
    ],

];
