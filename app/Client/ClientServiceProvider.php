<?php

declare(strict_types=1);

namespace App\Client;

use App\Client\Domain\Pricing\CentralPrices;
use App\Client\Domain\Stock\Reservations;
use App\Client\Portal\PortalPanelProvider;
use App\Domain\Sales\Contracts\Prices;
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
        // The buyer portal: a second panel on the customer guard (sub-project 4).
        $this->app->register(PortalPanelProvider::class);
        $this->app->singleton(Reservations::class);
        // Every selling line is priced by Central's rules: customer deals, the tier, the list in force.
        $this->app->bind(Prices::class, CentralPrices::class);
    }

    public function boot(): void {}
}
