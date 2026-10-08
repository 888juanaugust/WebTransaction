<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every request of the panel (Livewire's included) in the user's language, else the company's. */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        Locales::apply(Locales::forUser($request->user()));

        return $next($request);
    }
}
