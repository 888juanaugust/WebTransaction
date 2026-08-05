<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Where an unauthenticated staff request is sent.
         *
         * Authentication lives in the Filament panels, so this app has no route
         * named `login` — which is the name Laravel's Authenticate middleware
         * reaches for by default. Any staff route outside a panel (the surat
         * jalan, for one) therefore answered a logged-out visitor with a 500
         * from RouteNotFoundException instead of a redirect.
         *
         * Buyers are not considered here on purpose: they authenticate on the
         * `customer` guard against a different table, and every page they can
         * reach is inside the portal panel, which handles its own redirect.
         */
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
