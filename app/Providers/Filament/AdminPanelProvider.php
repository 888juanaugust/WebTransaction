<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Widgets\AccountsAwaitingApproval;
use App\Filament\Widgets\ArusStok;
use App\Filament\Widgets\BackupStatus;
use App\Filament\Widgets\DebtRemovalsAwaitingVerification;
use App\Filament\Widgets\ExpenseClaimsAwaitingVerification;
use App\Filament\Widgets\GiroDue;
use App\Filament\Widgets\LaunchReadinessSummary;
use App\Filament\Widgets\LedgerIntegrityStatus;
use App\Filament\Widgets\NilaiStokKategori;
use App\Filament\Widgets\OrdersAwaitingApproval;
use App\Filament\Widgets\OrdersReadyToPick;
use App\Filament\Widgets\OverdueInvoices;
use App\Filament\Widgets\PendapatanVsBeban;
use App\Filament\Widgets\PenjualanBulanan;
use App\Filament\Widgets\PiutangPerPelanggan;
use App\Filament\Widgets\ReturnsAwaitingVerification;
use App\Filament\Widgets\UnmatchedPayments;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName(config('perusahaan.nama_singkat'))
            /*
             * The mark alone. It used to carry the signed-in role beside it
             * so that "which account is this open on?" was answered in the
             * corner; that line now lives under the account's name at the
             * foot of the sidebar — see the USER_MENU_BEFORE hook below —
             * where the reference design puts it. Filament falls back to the
             * wordmark when there is no logo file.
             */
            ->brandLogo(fn () => Branding::logoUrl())
            ->darkModeBrandLogo(fn () => Branding::darkLogoUrl())
            ->brandLogoHeight('1.75rem')
            /*
             * Cairo, served from public/fonts. The design system asks for it
             * on all text; LocalFontProvider because Filament's default
             * provider for a named font fetches it from Google, and the
             * privacy notice promises no CDN webfonts.
             *
             * Only the latin subset is preloaded. latin-ext is declared in
             * cairo.css with its own unicode-range and the browser fetches it
             * only if a character in that range is actually drawn, which on
             * these screens means a pasted supplier name and not much else.
             *
             * The shopfront keeps Geist — see BrandColors::panel() on why the
             * panels and the public site have parted company for now.
             */
            ->font(
                'Cairo',
                url: fn (): string => asset('fonts/cairo.css'),
                provider: LocalFontProvider::class,
                preload: fn (): array => [asset('fonts/Cairo-Variable-latin.woff2')],
            )
            /*
             * The sidebar collapses, per the design system. Filament keeps the
             * choice per user in local storage, so a packer working one queue
             * all day can reclaim the width and a manager moving between
             * screens can keep the labels.
             */
            ->sidebarCollapsibleOnDesktop()
            ->login()
            /*
             * A profile page, which is the only way a staff member can change
             * their own password. Without it the launch checklist tells people
             * to visit a page that does not exist, the seeded `password` stays
             * on every account including the owner's, and that check can never
             * go green.
             */
            ->profile(isSimple: false)
            /*
             * The bell. Debt-aging reminders land here for the team in
             * charge of the customer — a queue emptied by paying attention,
             * which is the point of a reminder.
             */
            ->databaseNotifications()
            /*
             * The account lives at the foot of the sidebar — avatar, name,
             * chevron — not in the topbar. Same seat as the reference design,
             * and it frees the topbar for the region switcher and search.
             */
            ->userMenu(position: UserMenuPosition::Sidebar)
            /*
             * Initials drawn locally. Filament's default fetches the avatar
             * from ui-avatars.com with the account's name in the URL, which
             * the privacy notice says this site does not do — see
             * InitialsAvatar.
             */
            ->defaultAvatarProvider(InitialsAvatar::class)
            /*
             * Seven groups in a fixed order, declared once. Every resource
             * and page names one of them; see SidebarGroups for the shape and
             * the figures that made it necessary.
             */
            ->navigationGroups(SidebarGroups::panel())
            // Indigo surfaces, company red. See BrandColors for why the ramps
            // are declared rather than generated from hex.
            ->colors(BrandColors::panel())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            /*
             * Admin home is queues, not a dashboard of charts. Each widget is
             * a worklist somebody is expected to empty; generic CRUD lives in
             * the resources behind them, for corrections.
             *
             * Every widget scopes itself by role — the warehouse queue shows
             * no prices, the finance queues show no picking work.
             */
            ->widgets([
                /*
                 * Above the work queues, and silent unless something is wrong.
                 * The launch checklist goes first and goes away for good once
                 * the list is clear — before launch it is the only thing on
                 * this page that matters.
                 */
                LaunchReadinessSummary::class,
                BackupStatus::class,
                LedgerIntegrityStatus::class,
                OrdersAwaitingApproval::class,
                AccountsAwaitingApproval::class,
                UnmatchedPayments::class,
                // A claim that a debt was paid in cash, waiting on finance's
                // key. Sits with the other money queues.
                DebtRemovalsAwaitingVerification::class,
                // Returs sales filed, waiting for Inventori to confirm the
                // goods are physically back.
                ReturnsAwaitingVerification::class,
                // Road spending sales claimed, for finance's manual check.
                ExpenseClaimsAwaitingVerification::class,
                OrdersReadyToPick::class,
                OverdueInvoices::class,
                // Silent unless a giro is dated today or earlier.
                GiroDue::class,
                /*
                 * The graphs, below the queues on purpose: the queues are
                 * today's work, the charts are how the month is going. Each
                 * one scopes itself to the viewer's seat like everything
                 * above it.
                 */
                PenjualanBulanan::class,
                PiutangPerPelanggan::class,
                ArusStok::class,
                NilaiStokKategori::class,
                PendapatanVsBeban::class,
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
            /*
             * The region switcher, left of the search box. For the Owner it
             * is a select that changes which books every screen below reads;
             * for pinned staff it is a label naming the one region they work
             * in — both render nothing while only one region exists, so the
             * panel looks exactly as before until a second region is created.
             *
             * It used to hang off USER_MENU_BEFORE. That hook renders inside
             * the user menu wherever the menu is, and the menu is now in the
             * sidebar footer — so left there, the switcher would have
             * followed it down and out of the topbar it belongs in.
             */
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => auth()->check()
                    ? view('filament.wilayah-switcher')->render()
                    : '',
            )
            // The "Utama" heading above the menu, matching "Lainnya" above
            // the settings group (which the stylesheet draws, being the one
            // place a hook cannot reach).
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => view('filament.sidebar-bagian', ['label' => 'Utama'])->render(),
            )
            /*
             * Every group starts folded, so the one holding the current page
             * has to be opened by hand — Filament remembers folds and never
             * re-opens one. See the view for why this is a script and not a
             * stylesheet rule.
             */
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => view('filament.sidebar-buka-grup-aktif')->render(),
            )
            /*
             * The line under the account's name: the role, and the warehouse
             * for a packer. This is the information the brand view used to
             * put beside the logo, moved to where the reference design keeps
             * it. USER_MENU_BEFORE is the right hook now for exactly the
             * reason it was the wrong one for the switcher — it follows the
             * menu into the sidebar.
             */
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                function (): string {
                    $user = auth('web')->user();

                    if ($user === null) {
                        return '';
                    }

                    $keterangan = $user->role()->label();

                    if ($user->role()->isWarehouseBound() && $user->warehouse) {
                        $keterangan .= ' · '.$user->warehouse->nama;
                    }

                    return view('filament.akun-keterangan', ['keterangan' => $keterangan])->render();
                },
            )
            ->authMiddleware([
                Authenticate::class,
                /*
                 * After Authenticate, because it reads the signed-in account to
                 * decide which region everything below can see. In authMiddleware
                 * rather than middleware so it never runs on the login page,
                 * where there is nobody to bind it from.
                 *
                 * Persistent, because Filament runs non-persistent panel
                 * middleware only on full page loads. A table search, a widget
                 * refresh, and every button on a Livewire component arrive as
                 * /livewire/update — without the region bound there, reads fall
                 * open to every region and writes throw.
                 */
                BindRegionContext::class,
            ], isPersistent: true);
    }
}
