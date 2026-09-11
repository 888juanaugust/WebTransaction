<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * A real, enforced Content-Security-Policy — for the public site only.
 *
 * The panels keep their documented deferral (Livewire and Filament lean on
 * inline scripts, and a CSP there is nonce plumbing through the whole asset
 * pipeline). The public site has no such excuse: its assets are self-hosted,
 * it has no inline styles, and its only two inline scripts live in one
 * layout — so they carry a per-request nonce and everything else is denied.
 *
 * What this buys: if an XSS hole ever appears in a public page — a config
 * value gone wrong, a future template mistake — the injected script does not
 * run, because it cannot know the nonce. CSP is the backstop, not the
 * defence; Blade's auto-escaping remains the defence.
 *
 * The JSON-LD block needs no nonce: a script element with a non-JavaScript
 * type is a data block the browser never executes, so CSP has nothing to
 * deny it.
 */
class PublicContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));

        // The layout stamps this onto its two inline <script> tags.
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        /*
         * The one browser capability the public site asks for, and only on
         * its own origin: the contact page's nearest-branch button. Named
         * here so the policy is a statement of what the site does, not a
         * default it inherited. Everything else stays off.
         */
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()');

        return $response;
    }
}
