<?php

declare(strict_types=1);

namespace App\Client\Site\Http;

use App\Client\Site\Copy;
use App\Domain\Shared\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which language the public site speaks to this visitor: Bahasa Indonesia
 * unless they asked for English. A choice made once, kept in a cookie for a
 * year, never guessed from the browser's headers. Public routes only; the
 * panels read the user's own profile.
 */
class SiteLocale
{
    public const COOKIE = 'bahasa';

    public const DEFAULT = 'id';

    public function handle(Request $request, Closure $next): Response
    {
        $choice = (string) $request->cookie(self::COOKIE, '');
        Locales::apply(in_array($choice, Copy::LANGUAGES, true) ? $choice : self::DEFAULT);

        return $next($request);
    }
}
