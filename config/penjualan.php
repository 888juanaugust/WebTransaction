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
     | Debt aging, counted in days from the invoice's issue date — the
     | transaction date, not the due date.
     |
     | At `debt_notice_days` (120) the customer and the team in charge of
     | them are told the debt is aging. Strictly after `debt_freeze_days`
     | (150) the customer falls due: no new transaction until the aged
     | invoice is settled. Days rather than months since the 2026-08 terms
     | change — "seratus dua puluh hari" is what the owner now says.
     |
     | Distinct from the faktur's own due date (payment_terms_days on the
     | customer, default 30): that is the promise printed on the paper;
     | these are the lines where the organisation acts on it being broken.
     */
    'debt_notice_days' => (int) env('DEBT_NOTICE_DAYS', 120),
    'debt_freeze_days' => (int) env('DEBT_FREEZE_DAYS', 150),

    /*
     | Safety brake on price list imports. Crossing either of these requires a
     | second confirmation that names the numbers.
     */
    'import_brake' => [
        'max_changed_share_bps' => 2_000,   // >20% of prices changed
        'max_single_move_bps' => 5_000,     // any one price moving >50%
    ],

];
