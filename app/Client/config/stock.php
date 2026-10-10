<?php

return [
    // The staff account scheduled documents (count sheets) are written under; no person holds its password.
    'system_email' => env('SYSTEM_ACTOR_EMAIL', 'system@central.local'),
    // When the day's count sheet is drafted (after picking), and the months whose first day starts a full count.
    'daily_sheet_at' => '17:30',
    'semester_months' => [1, 7],
    // How many SKUs a day's sheet may hold at most; the rest wait for the next sheet.
    'daily_sheet_max' => 200,
];
