<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which language the public site speaks to this visitor.
 *
 * Bahasa Indonesia unless they have asked for English. The site introduces
 * an Indonesian wholesaler to Indonesian workshops; English exists for the
 * overseas suppliers and partners who also read it, and it is a choice they
 * make once — a cookie, a year long — rather than a guess made from their
 * browser's headers, which would hand an Indonesian on an English-locale
 * laptop a page in the wrong language with no obvious way back.
 *
 * Public routes only. The panels and every printed document are Indonesian
 * by construction and never consult this; the legal pages are Indonesian by
 * law and declare their own `lang` whatever this says.
 */
class PublicLocale
{
    public const COOKIE = 'bahasa';

    public const DEFAULT = 'id';

    /** @var list<string> */
    public const SUPPORTED = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $pilihan = (string) $request->cookie(self::COOKIE, '');

        app()->setLocale(in_array($pilihan, self::SUPPORTED, true) ? $pilihan : self::DEFAULT);

        return $next($request);
    }

    /** The other language, for the switcher: the one link it offers. */
    public static function lainnya(): string
    {
        return app()->getLocale() === 'en' ? 'id' : 'en';
    }
}
