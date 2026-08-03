<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tax\TaxCalculator;
use App\Jobs\ReleaseStaleReservations;
use App\Models\Company;
use App\Observers\CompanyObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Everything else in App\Domain is constructor-injectable as-is; only
        // the tax calculator needs config to build.
        $this->app->singleton(TaxCalculator::class, fn () => TaxCalculator::fromConfig());
    }

    public function boot(): void
    {
        // Catch "$model->undefined_column = x" typos before they silently
        // drop a money field on the floor.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Company::observe(CompanyObserver::class);

        Schedule::job(new ReleaseStaleReservations)->everyFifteenMinutes();
    }
}
