<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Xendit
|--------------------------------------------------------------------------
|
| Fixed Virtual Account only. A buyer company gets one VA per bank and keeps
| it forever; incoming transfers arrive as callbacks.
|
| `paid` is set only by the webhook — never by a browser redirect, never by a
| controller responding to a user action.
|
*/

return [

    'secret_key' => env('XENDIT_SECRET_KEY'),

    // Xendit sends this verbatim in the x-callback-token header.
    'callback_token' => env('XENDIT_CALLBACK_TOKEN'),

    'base_url' => env('XENDIT_BASE_URL', 'https://api.xendit.co'),

    // Banks we are willing to open a fixed VA with.
    'va_banks' => ['BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA'],

];
