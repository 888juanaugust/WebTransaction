<?php

declare(strict_types=1);

namespace App\Client\Portal\Http;

use App\Client\Models\CustomerUser;
use App\Domain\Shared\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every portal request in the buyer's language when they chose one, else the company's. */
class PortalLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $buyer = $request->user('customer');
        $own = $buyer instanceof CustomerUser ? $buyer->locale : null;
        Locales::apply(is_string($own) && isset(Locales::names()[$own]) ? $own : Locales::companyDefault());

        return $next($request);
    }
}
