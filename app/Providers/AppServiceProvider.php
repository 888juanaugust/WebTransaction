<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Payments\LocalVirtualAccountGateway;
use App\Domain\Payments\VirtualAccountGateway;
use App\Domain\Payments\XenditVirtualAccountGateway;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Tax\EFakturCsvWriter;
use App\Domain\Tax\FakturWriter;
use App\Domain\Tax\TaxCalculator;
use App\Jobs\ReleaseStaleReservations;
use App\Jobs\SweepStuckWebhookEvents;
use App\Models\Company;
use App\Observers\CompanyObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Everything else in App\Domain is constructor-injectable as-is; only
        // the tax calculator needs config to build.
        $this->app->singleton(TaxCalculator::class, fn () => TaxCalculator::fromConfig());

        /*
         * Which file layout the tax office wants is not settled — see
         * FakturWriter for the whole of it. Bound from config rather than
         * hard-wired so the answer, when the accountant produces a real
         * template, is a config value and a new writer class rather than a
         * change to anything that calls it.
         */
        $this->app->bind(FakturWriter::class, function () {
            return match ((string) config('pajak.format_ekspor')) {
                'efaktur_csv' => new EFakturCsvWriter,
                default => throw new InvalidArgumentException(
                    'Format ekspor faktur tidak dikenal: '.config('pajak.format_ekspor')
                ),
            };
        });

        /*
         * One price resolver per request and per queue job.
         *
         * It caches the rows it reads, which is what keeps pricing a 20-line
         * order from running 80 queries. `scoped` rather than `singleton` so a
         * long-lived queue worker starts each job with an empty cache instead
         * of pricing next week's orders from a version it read on Monday.
         */
        $this->app->scoped(PriceResolver::class);

        /*
         * VA provisioning talks to Xendit only when there is a key to talk
         * with. Without one — a developer's laptop, CI — accounts are minted
         * locally so the order → invoice → payment chain still runs end to
         * end. A flow you cannot complete locally is a flow people end up
         * testing on production data.
         */
        $this->app->bind(VirtualAccountGateway::class, function () {
            return filled(config('xendit.secret_key'))
                ? new XenditVirtualAccountGateway
                : new LocalVirtualAccountGateway;
        });
    }

    public function boot(): void
    {
        // Catch "$model->undefined_column = x" typos before they silently
        // drop a money field on the floor.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Company::observe(CompanyObserver::class);

        Schedule::job(new ReleaseStaleReservations)->everyFifteenMinutes();

        // Recovers money stranded by a worker that died mid-callback. Nothing
        // else will: the gateway already got its 200 and will not redeliver.
        Schedule::job(new SweepStuckWebhookEvents)->everyFiveMinutes();
    }
}
