<?php

declare(strict_types=1);

namespace App\Client\Site\Http;

use App\Client\Site\Copy;
use Illuminate\Http\RedirectResponse;

/**
 * The language switch: a plain link, no form, no script. Sets the cookie for
 * a year and sends the visitor back to the page they were reading, on this
 * host only, so the referer cannot turn it into an open redirect.
 */
class LanguageController
{
    public function __invoke(string $code): RedirectResponse
    {
        abort_unless(in_array($code, Copy::LANGUAGES, true), 404);

        $back = url()->previous();
        $to = str_starts_with($back, url('/').'/') || $back === url('/') ? $back : url('/');

        return redirect($to)->withCookie(cookie()->forever(SiteLocale::COOKIE, $code));
    }
}
