<?php

declare(strict_types=1);

namespace App\Client\Portal\Http;

use App\Client\Models\CustomerUser;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A buyer whose login or customer was deactivated while signed in is signed
 * out at their next click and sent to the sign-in page, where they read why.
 */
final class EndInactiveBuyerSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Filament::auth();
        $user = $guard->user();
        if (! $user instanceof CustomerUser || $user->canAccessPanel(Filament::getCurrentPanel())) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $message = __('Your portal access has been deactivated.');
        abort_if($request->hasHeader('X-Livewire'), 403, $message);
        Notification::make()->title($message)->danger()->persistent()->send();

        return redirect()->to(Filament::getLoginUrl());
    }
}
