<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * The proxies in front of the app (TRUSTED_PROXIES: addresses or ranges, comma-separated, or "*"), read from the
 * config so a cached config keeps them. Behind them, the visitor's address and https are taken from the
 * forwarded headers: the sign-in throttle and the audit log see the visitor, not the proxy.
 */
class TrustProxiesFromConfig extends TrustProxies
{
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies'));
        if ($configured === '') {
            return parent::proxies();
        }

        return $configured === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
