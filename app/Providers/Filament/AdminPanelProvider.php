<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Widgets\AccountsAwaitingApproval;
use App\Filament\Widgets\BackupStatus;
use App\Filament\Widgets\GiroDue;
use App\Filament\Widgets\LaunchReadinessSummary;
use App\Filament\Widgets\OrdersAwaitingApproval;
use App\Filament\Widgets\OrdersReadyToPick;
use App\Filament\Widgets\OverdueInvoices;
use App\Filament\Widgets\UnmatchedPayments;
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
            // Null until the logo file is committed; Filament then falls back
            // to the brand name, so a missing file is a wordmark rather than a
            // broken image.
            ->brandLogo(fn () => Branding::logoUrl())
            ->brandLogoHeight('1.75rem')
            ->login()
            // Clean white surfaces, company blue, company red. See BrandColors
            // for why the ramps are declared rather than generated from hex.
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
                OrdersAwaitingApproval::class,
                AccountsAwaitingApproval::class,
                UnmatchedPayments::class,
                OrdersReadyToPick::class,
                OverdueInvoices::class,
                // Silent unless a giro is dated today or earlier.
                GiroDue::class,
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
            ]);
    }
}
