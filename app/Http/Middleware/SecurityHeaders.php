<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers every surface carries.
 *
 * Small and deliberate rather than exhaustive:
 *
 * - `nosniff`, because the portal serves buyer-facing downloads (faktur,
 *   surat jalan, CSV) and a browser second-guessing content types is how a
 *   download becomes a script.
 * - `X-Frame-Options: DENY` — no page here is meant to live in someone
 *   else's iframe, and a framed login is a phishing kit. Printing opens in
 *   a tab, not a frame, so nothing legitimate breaks.
 * - `Referrer-Policy: same-origin` — document numbers sit in URLs
 *   (/dokumen/faktur/123), and a buyer following an outbound link should
 *   not hand our path to the destination.
 *
 * No Content-Security-Policy yet, and that is a decision rather than an
 * oversight: Livewire and Filament lean on inline scripts, so a real CSP
 * here is nonce plumbing through the whole asset pipeline — worth doing,
 * not worth doing as a side effect of a launch checklist.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
