<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Portal\Widgets\KreditTersedia;
use App\Filament\Portal\Widgets\OrderTerakhir;
use App\Filament\Portal\Widgets\TagihanTerbuka;
use App\Http\Middleware\BindRegionContext;
use App\Support\BrandColors;
use App\Support\Branding;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The buyer portal.
 *
 * Authenticates on the `customer` guard against customer_users, so a buyer
 * session has no identity on the staff guard and cannot reach /admin even in
 * principle.
 *
 * B2B buyers restock; they don't shop. The landing screen is ordered the way
 * the spec demands: what they owe and what they have left to spend, then their
 * last order to repeat, then their open invoices.
 */
class PortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('portal')
            ->authGuard('customer')
            ->brandName(config('perusahaan.nama_singkat').' — Portal Pelanggan')
            ->brandLogo(fn () => Branding::logoUrl())
            ->brandLogoHeight('1.75rem')
            ->login()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors(BrandColors::panel())
            ->discoverResources(in: app_path('Filament/Portal/Resources'), for: 'App\Filament\Portal\Resources')
            ->discoverPages(in: app_path('Filament/Portal/Pages'), for: 'App\Filament\Portal\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Portal/Widgets'), for: 'App\Filament\Portal\Widgets')
            ->widgets([
                KreditTersedia::class,
                OrderTerakhir::class,
                TagihanTerbuka::class,
            ])
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
            ->authMiddleware([
                Authenticate::class,
                /*
                 * A buyer's region comes from the company they belong to. This
                 * sits underneath ScopedToBuyer rather than replacing it: that
                 * trait keeps one customer out of another's rows, this keeps a
                 * whole region's data out of a request that has no business
                 * touching it.
                 *
                 * Persistent for the same reason as the admin panel: Livewire
                 * update requests — the cart buttons, every table search —
                 * bypass non-persistent panel middleware, and the region must
                 * be bound on those too.
                 */
                BindRegionContext::class,
            ], isPersistent: true);
    }
}
