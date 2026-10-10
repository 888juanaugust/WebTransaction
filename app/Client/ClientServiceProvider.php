<?php

declare(strict_types=1);

namespace App\Client;

use App\Client\Domain\Debt\AcceptanceAging;
use App\Client\Domain\Ops\Backup\BackupCipher;
use App\Client\Domain\Ops\Backup\DatabaseDumper;
use App\Client\Domain\Ops\Backup\FileArchiver;
use App\Client\Domain\Ops\Launch\LaunchReadiness;
use App\Client\Domain\Pricing\CentralPrices;
use App\Client\Domain\Stock\Reservations;
use App\Client\Portal\PortalPanelProvider;
use App\Client\Site\Http\SiteContentSecurityPolicy;
use App\Client\Site\Http\SiteLocale;
use App\Client\Site\SiteSettings;
use App\Domain\Sales\Contracts\AgingDate;
use App\Domain\Sales\Contracts\Prices;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Central's own code lives under app/Client and boots from here, last of
 * all providers, so it can override anything the base registered. What
 * belongs here:
 *
 *  - extra modules (listed in config/client.php under "modules"), each with
 *    its own resources, pages, models, migrations and seeders;
 *  - bindings that replace a base service (bind the interface or class
 *    to Central's implementation in register());
 *  - extra blockers, ledger writers or fulfilment chains, wired in boot()
 *    through the same services the standard modules use (see
 *    App\Modules\ModuleContext);
 *  - Central's translations (lang/<locale>.json), print layouts and the
 *    buyer portal's panel.
 *
 * Never patch a base module in place; override it from here, so the base
 * stays readable as the base (CLAUDE.md, Architecture).
 */
class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/pricelist.php', 'pricelist');
        $this->mergeConfigFrom(__DIR__.'/config/claims.php', 'claims');
        $this->mergeConfigFrom(__DIR__.'/config/portal.php', 'portal');
        $this->mergeConfigFrom(__DIR__.'/config/site.php', 'site');
        $this->mergeConfigFrom(__DIR__.'/config/orders.php', 'orders');
        $this->mergeConfigFrom(__DIR__.'/config/ops.php', 'ops');
        // The buyer portal: a second panel on the customer guard (sub-project 4).
        $this->app->register(PortalPanelProvider::class);
        $this->app->singleton(Reservations::class);
        $this->app->scoped(SiteSettings::class);
        $this->app->scoped(LaunchReadiness::class);
        // Backups: the cipher, the dumper and the archiver read their config when asked for, never earlier.
        $this->app->bind(BackupCipher::class, fn () => BackupCipher::fromConfig());
        $this->app->bind(DatabaseDumper::class, fn () => DatabaseDumper::fromConfig());
        $this->app->bind(FileArchiver::class, fn () => FileArchiver::fromConfig());
        // Every selling line is priced by Central's rules: customer deals, the tier, the list in force.
        $this->app->bind(Prices::class, CentralPrices::class);
        $this->app->bind(AgingDate::class, AcceptanceAging::class); // an invoice ages from its order's approval
    }

    public function boot(): void
    {
        // The public site (sub-project 5). Registered once the application has
        // booted, after the base's routes/web.php, so the site's "/" replaces
        // the base's redirect to the panel: for one method and URI the route
        // registered last wins. The route cache is built the same way.
        $this->app->booted(function (): void {
            if ($this->app->routesAreCached()) {
                return;
            }
            Route::middleware(['web', SiteLocale::class, SiteContentSecurityPolicy::class])->group(__DIR__.'/routes/site.php');
        });
    }
}
