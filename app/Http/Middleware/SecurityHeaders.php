<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every response carries. The content policy keeps everything on this site: no script, style, font or
 * frame from elsewhere, no other site framing these pages (the workspace frames its own tabs), no <base> or
 * <object> injection, forms posting only here. Scripts keep 'unsafe-inline' and 'unsafe-eval' because the panel's
 * own inline scripts and Alpine's expressions need them; escaping output is what stops injected markup.
 */
class SecurityHeaders
{
    public const POLICY = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-src 'self' blob:; "
        ."frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $headers->set('Content-Security-Policy', self::POLICY, false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()', false);
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }

        return $response;
    }
}
