<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user deactivated while signed in is signed out at their next click and
 * sent to the sign-in page, where they read why; a background request
 * (Livewire) is refused, and the next page they open does the rest.
 */
final class EndInactiveSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Filament::auth();
        $user = $guard->user();
        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = __('Your account has been deactivated.');
        abort_if($request->hasHeader('X-Livewire'), 403, $message);
        Notification::make()->title($message)->danger()->persistent()->send();

        return redirect()->to(Filament::getLoginUrl());
    }
}
