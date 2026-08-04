<?php

declare(strict_types=1);

use App\Http\Controllers\XenditWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Situs publik
|--------------------------------------------------------------------------
|
| Open and indexed. No prices appear on any of these pages — wholesale
| pricing is per customer, and public price display is out of scope for v1.
|
| Content comes from config/perusahaan.php, not from these templates.
|
*/

Route::view('/', 'publik.beranda')->name('publik.beranda');
Route::view('/tentang-kami', 'publik.tentang')->name('publik.tentang');
Route::view('/mitra', 'publik.mitra')->name('publik.mitra');
Route::view('/rencana-pengembangan', 'publik.rencana')->name('publik.rencana');
Route::view('/kontak', 'publik.kontak')->name('publik.kontak');

/*
 * Login chooser. Staff and buyers authenticate on different guards against
 * different tables, so this page routes to one of two panels rather than
 * trying to work out which kind of account an address belongs to.
 *
 *   /admin   staff   (web guard, users)
 *   /portal  buyers  (customer guard, customer_users)
 */
Route::view('/masuk', 'publik.masuk')->name('masuk');

/*
|--------------------------------------------------------------------------
| Callback gateway
|--------------------------------------------------------------------------
|
| Exempt from CSRF — Xendit authenticates with the x-callback-token header,
| which the controller checks in constant time before touching anything.
|
*/

Route::post('/webhooks/xendit', XenditWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.xendit');
