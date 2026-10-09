<?php

declare(strict_types=1);

namespace App\Client\Portal;

use App\Client\Models\CustomerUser;
use App\Client\Portal\Filament\Pages\Home;
use App\Client\Portal\Http\EndInactiveBuyerSessions;
use App\Client\Portal\Http\PortalLocale;
use App\Domain\Audit\Auditor;
use App\Filament\Support\InitialsAvatar;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Enums\ThemeMode;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The buyer portal: a second panel at /portal for the company's customers,
 * on the customer guard, built from the same tokens and typeface as the
 * workspace but not the workspace — a flat sidebar, the buyer's own pages.
 */
class PortalPanelProvider extends PanelProvider
{
    public const ID = 'portal';

    public function boot(): void
    {
        // A deactivated buyer is signed out before the panel's own check would only refuse them (as the base does for staff).
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'getMiddlewarePriority')) {
            $priority = $kernel->getMiddlewarePriority();
            $at = array_search(AuthenticatesRequests::class, $priority, true);
            if ($at !== false && ! in_array(EndInactiveBuyerSessions::class, $priority, true)) {
                array_splice($priority, $at, 0, [EndInactiveBuyerSessions::class]);
                $kernel->setMiddlewarePriority($priority);
            }
        }

        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof CustomerUser) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
        // A buyer setting or resetting their password is on the record, with nobody as the actor.
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof CustomerUser) {
                Auditor::log('portal_password_reset', $event->user, null, ['email' => $event->user->email]);
            }
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(self::ID)
            ->path('portal')
            ->authGuard('customer')
            ->authPasswordBroker('customer_users')
            ->login()
            ->passwordReset()
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->brandName(fn (): string => AdminPanelProvider::brandName().' · '.__('Portal'))
            ->colors(array_merge([
                'primary' => '#2f5bea',
                'gray' => Color::Slate,
                'success' => '#166534',
                'warning' => '#8a5a00',
                'danger' => '#a11d1d',
                'info' => '#2f5bea',
            ], array_filter((array) config('client.theme.colors', []))))
            ->font('Geist Variable', provider: LocalFontProvider::class)
            ->monoFont('Geist Mono Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/portal/theme.css')
            ->darkMode(true)
            ->defaultThemeMode(ThemeMode::Light)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::SevenExtraLarge)
            ->spa()
            ->databaseTransactions()
            ->discoverResources(in: app_path('Client/Portal/Filament/Resources'), for: 'App\Client\Portal\Filament\Resources')
            ->discoverPages(in: app_path('Client/Portal/Filament/Pages'), for: 'App\Client\Portal\Filament\Pages')
            ->discoverWidgets(in: app_path('Client/Portal/Filament/Widgets'), for: 'App\Client\Portal\Filament\Widgets')
            ->pages([Home::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([EndInactiveBuyerSessions::class, PortalLocale::class], isPersistent: true)
            ->authMiddleware([Authenticate::class], isPersistent: true);
    }
}
