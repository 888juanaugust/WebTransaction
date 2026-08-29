<?php

use App\Http\Middleware\SecurityHeaders;
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
         * Buyers get their own answer. They authenticate on the `customer`
         * guard against a different table, so sending one to the staff login
         * hands them a form their credentials cannot possibly satisfy — and
         * then, once they do log in as themselves, drops them somewhere that
         * is not where they were going. The customer's faktur lives outside the
         * portal panel, so the panel's own redirect does not cover it.
         *
         * Decided by path, not by guessing at the visitor: a guest has no
         * identity to inspect, and the path is the only honest signal about
         * which door they were knocking on.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('portal', 'portal/*')
            ? route('filament.portal.auth.login')
            : route('filament.admin.auth.login'));

        // Every surface — public site, both panels, printable documents.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
