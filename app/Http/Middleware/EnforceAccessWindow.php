<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\AccessWindow;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Turns an operator away outside the hours their access groups (or Preferences) allow. */
final class EnforceAccessWindow
{
    public function __construct(private readonly AccessWindow $window) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! $this->window->allows($user)) {
            $hours = $this->window->hoursFor($user);
            abort(403, $hours === null
                ? __('Access is closed for your account.')
                : __('Access is open only :hours.', ['hours' => $hours]));
        }

        return $next($request);
    }
}
