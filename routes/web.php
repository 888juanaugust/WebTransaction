<?php

declare(strict_types=1);

use App\Http\Controllers\XenditWebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 * Public site lives here later (open, indexed, no prices shown).
 * The admin panel is served by Filament at /admin.
 */
Route::get('/', fn () => view('welcome'));

/*
 * Gateway callbacks. Exempt from CSRF — Xendit authenticates with the
 * x-callback-token header, which the controller checks in constant time
 * before touching anything.
 */
Route::post('/webhooks/xendit', XenditWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('webhooks.xendit');
