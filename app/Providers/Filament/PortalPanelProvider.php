<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Portal\Widgets\GrafikBelanja;
use App\Filament\Portal\Widgets\GrafikUmurTagihan;
use App\Filament\Portal\Widgets\KreditTersedia;
use App\Filament\Portal\Widgets\OrderTerakhir;
use App\Filament\Portal\Widgets\TagihanTerbuka;
use App\Http\Middleware\BindRegionContext;
use App\Support\BrandColors;
use App\Support\Branding;
use App\Support\InitialsAvatar;
use Filament\Enums\UserMenuPosition;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
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
            // The same mark in a lighter blue once the panel is switched to
            // dark; without it the navy mark sinks into the dark sidebar.
            ->darkModeBrandLogo(fn () => Branding::darkLogoUrl())
            ->brandLogoHeight('1.75rem')
            /*
             * Cairo, served from public/fonts — the same face and the same
             * local provider as the admin panel, so the two staff-and-buyer
             * surfaces stay one design system. See AdminPanelProvider for why
             * the font is committed rather than fetched.
             */
            ->font(
                'Cairo',
                url: fn (): string => asset('fonts/cairo.css'),
                provider: LocalFontProvider::class,
                preload: fn (): array => [asset('fonts/Cairo-Variable-latin.woff2')],
            )
            /*
             * Collapsible here too. A buyer reorders the same fifteen SKUs
             * forever and mostly wants the table, not the menu.
             */
            ->sidebarCollapsibleOnDesktop()
            ->login()
            /*
             * Self-service reset, on the buyer's own broker — its own token
             * table, so a buyer and a staff member sharing an email can never
             * share a token. The admin panel deliberately has no such link:
             * staff passwords are reset by the Owner through the staff
             * screen, where the change is audited to a person.
             */
            ->passwordReset()
            ->authPasswordBroker('customer_users')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors(BrandColors::panel())
            /*
             * The buyer's account at the foot of the sidebar, like the staff
             * panel: avatar, name, and under it the company they buy for —
             * one login can only ever belong to one company, and on a shared
             * shop computer that line is the answer to "whose cart is this".
             */
            ->userMenu(position: UserMenuPosition::Sidebar)
            // Local initials, not ui-avatars.com — see InitialsAvatar.
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                function (): string {
                    $buyer = auth('customer')->user();

                    return $buyer?->company
                        ? view('filament.akun-keterangan', ['keterangan' => $buyer->company->nama])->render()
                        : '';
                },
            )
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
                // The buyer's graphs: spend by month, and debt by age with
                // the 150-day cliff visible before it bites.
                GrafikBelanja::class,
                GrafikUmurTagihan::class,
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
