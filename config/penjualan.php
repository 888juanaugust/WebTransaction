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
     | Debt aging, counted from the invoice's issue date.
     |
     | At `debt_notice_months` the customer and the team in charge of them are
     | told the debt is aging. At `debt_freeze_months` — strictly after that
     | day, i.e. four months plus one day — the customer falls due: no new
     | transaction until the aged invoice is settled. Whole months rather than
     | days on purpose: "tiga bulan" is what the owner said and what the
     | marketing will repeat on the phone, and 90 days is not three months.
     */
    'debt_notice_months' => (int) env('DEBT_NOTICE_MONTHS', 3),
    'debt_freeze_months' => (int) env('DEBT_FREEZE_MONTHS', 4),

    /*
     | Safety brake on price list imports. Crossing either of these requires a
     | second confirmation that names the numbers.
     */
    'import_brake' => [
        'max_changed_share_bps' => 2_000,   // >20% of prices changed
        'max_single_move_bps' => 5_000,     // any one price moving >50%
    ],

];
