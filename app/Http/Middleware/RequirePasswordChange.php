<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user who must change their password, or an administrator who must set up a second factor (Preferences →
 * Restrictions), sees only the profile page until they have.
 */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->profileFirst() === null) {
            return $next($request);
        }
        // The profile page itself and signing out stay open. A Livewire request runs this middleware again against the
        // route of the page its component lives on (it is registered as persistent), so the profile page's own
        // requests pass by their route; a header the browser sends proves nothing.
        if ($request->routeIs('filament.admin.auth.profile', 'filament.admin.auth.logout')) {
            return $next($request);
        }

        return redirect()->to(EditProfile::getUrl());
    }
}
