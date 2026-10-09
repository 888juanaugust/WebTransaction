<?php

declare(strict_types=1);

namespace App\Client\Site\Http;

use Closure;
use Illuminate\Http\Request;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Symfony\Component\HttpFoundation\Response;

/**
 * A second, stricter content policy for the public site. The base's global
 * header keeps 'unsafe-inline' for the panels; two policies are both
 * enforced, so the site runs under this one: no inline script, no inline
 * style, nothing from another origin, no framing at all. The site's
 * JavaScript is one bundled file; JSON-LD is a data block the browser never
 * executes. Blade's escaping stays the defence; this is the backstop.
 */
class SiteContentSecurityPolicy
{
    public const POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
        ."connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', self::POLICY, false);
        // No Livewire on the site: its injected inline style and script would only be refused by the policy above.
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        SupportAutoInjectedAssets::$forceAssetInjection = false;

        return $response;
    }
}
