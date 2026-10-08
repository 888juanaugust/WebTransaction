<?php

declare(strict_types=1);

namespace App\Client;

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
    public function register(): void {}

    public function boot(): void {}
}
