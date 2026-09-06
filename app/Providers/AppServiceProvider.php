<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Backup\BackupCipher;
use App\Domain\Backup\DatabaseDumper;
use App\Domain\Backup\FileArchiver;
use App\Domain\Launch\LaunchReadiness;
use App\Domain\Ops\OpsAlerter;
use App\Domain\Ops\OpsHealth;
use App\Domain\Pengaturan\PengaturanPerusahaan;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Regions\RegionContext;
use App\Domain\Tax\EFakturCsvWriter;
use App\Domain\Tax\FakturWriter;
use App\Domain\Tax\TaxCalculator;
use App\Filament\Navigation\RataGrupSatuLayar;
use App\Jobs\PruneAbandonedCarts;
use App\Jobs\PurgeVisitPhotos;
use App\Jobs\ReleaseStaleReservations;
use App\Jobs\SweepDebtAging;
use App\Jobs\SweepLedgerIntegrity;
use App\Models\Company;
use App\Models\CustomerUser;
use App\Observers\CompanyObserver;
use Filament\Navigation\NavigationManager;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * A sidebar group holding one visible screen renders as a plain row.
         * Filament binds its navigation assembler `scoped`, so re-binding it
         * here — this provider registers after the package's — replaces it for
         * both panels at once. See `RataGrupSatuLayar` for why the flattening
         * cannot happen inside the assembler itself.
         */
        $this->app->scoped(NavigationManager::class, fn (): NavigationManager => new RataGrupSatuLayar);

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
        /*
         * Backup pieces, built from config rather than injected.
         *
         * `bind` rather than `singleton` on purpose: each is a thin wrapper
         * over configuration that a restore or a test legitimately changes
         * mid-process, and a cached instance would keep answering with the
         * settings that were in force when it was first asked for.
         */
        $this->app->bind(BackupCipher::class, fn () => BackupCipher::fromConfig());
        $this->app->bind(DatabaseDumper::class, fn () => DatabaseDumper::fromConfig());
        $this->app->bind(FileArchiver::class, fn () => FileArchiver::fromConfig());

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
         * One launch checklist per request, for the same reason.
         *
         * The dashboard widget asks whether to show itself and then asks again
         * for what to show; the page asks three more times from its template.
         * Each pass runs bcrypt once per staff account, which is deliberately
         * slow. `scoped` rather than `singleton` so a queue worker does not
         * hold Monday's answer all week.
         */
        $this->app->scoped(LaunchReadiness::class);

        /*
         * Which region this request is working in.
         *
         * `scoped`, and it has to be. A global scope on thirty models reads
         * this object, so a singleton on a long-lived queue worker would carry
         * one job's region into the next — and the symptom would be a Surabaya
         * document filed in Jakarta's books, discovered by somebody reading a
         * neraca months later.
         *
         * Unbound until something binds it. Console commands and queue jobs
         * therefore see every region, which is what a nightly reconciliation
         * needs; the panels bind it per request from whoever signed in.
         */
        $this->app->scoped(RegionContext::class);

        /*
         * Region filtering for queries the HasRegion scope cannot reach.
         *
         * The global scope covers every query that starts from a scoped model.
         * Two shapes escape it: raw `DB::table()` builders in the reports, and
         * aggregates that start from a line table and join their scoped head —
         * the line carries no region on purpose, so the head must be filtered
         * by hand. This macro is that hand, written once: a no-op when nothing
         * is bound (jobs, console), a WHERE on the named table when pinned.
         */
        QueryBuilder::macro('whereBoundRegion', function (string $table): QueryBuilder {
            /** @var QueryBuilder $this */
            $regionId = app(RegionContext::class)->regionId();

            return $regionId === null ? $this : $this->where("{$table}.region_id", $regionId);
        });
    }

    public function boot(): void
    {
        /*
         * Debug mode in production leaks stack traces — file paths, SQL,
         * and often credentials — to whoever triggers an error. The .env is
         * supposed to say APP_DEBUG=false, and this line makes the leak
         * impossible even on the day someone forgets: production forces
         * debug off, whatever the file says. Fail safe, not fail loud.
         */
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
        }

        // Catch "$model->undefined_column = x" typos before they silently
        // drop a money field on the floor.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        /*
         * Behind Caddy every request arrives over TLS, but PHP sees the
         * proxied hop. Forcing the scheme keeps every generated URL — panel
         * redirects, the faktur link in a reset email — on https, so a
         * session cookie marked Secure never gets a plain-http URL to leak
         * on. Production only: local `php artisan serve` is http and should
         * stay usable.
         */
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        /*
         * A buyer resetting their own password leaves a trail. No actor —
         * the buyer is not a staff user and the audit log's actor column
         * means staff — but the subject names the account, which is what an
         * incident review needs: when did this login's credential change.
         */
        Event::listen(
            PasswordReset::class,
            function (PasswordReset $event): void {
                if ($event->user instanceof CustomerUser) {
                    app(AuditLogger::class)->log(
                        action: 'customer_password_reset',
                        subject: $event->user,
                        actor: null,
                    );
                }
            },
        );

        Company::observe(CompanyObserver::class);

        /*
         * What the Owner typed into Pengaturan perusahaan lands on top of
         * config here, so the faktur, the portal, the public site and the
         * launch checklist all see it without knowing it exists. Guarded
         * inside overlay(): booting with no database must not fatal.
         */
        app(PengaturanPerusahaan::class)->overlay();

        /*
         * A query that takes over a second on this workload is a bug, not a
         * load problem — twenty orders a day does not produce slow queries,
         * missing indexes and N+1s do. Logged in production only: locally
         * the suite and seeders would drown the log in cold-cache noise.
         */
        if ($this->app->isProduction()) {
            DB::listen(function ($query): void {
                if ($query->time > 1_000) {
                    Log::warning('Kueri lambat', [
                        'ms' => $query->time,
                        'sql' => $query->sql,
                    ]);
                }
            });
        }

        /*
         * The scheduler proves it is alive by saying so. OpsHealth reads
         * this stamp; a missing or stale heartbeat is the finding "cron is
         * not running", which otherwise announces itself as stock quietly
         * staying fenced.
         */
        Schedule::call(fn () => Cache::put(
            OpsHealth::HEARTBEAT_KEY, time(),
        ))->everyMinute()->name('ops-heartbeat');

        // The hourly sweep that mails the Owner when the box is broken —
        // once per incident; OpsAlerter owns the throttle.
        Schedule::call(fn () => app(OpsAlerter::class)->sweep())
            ->hourly()->name('ops-alert-sweep');

        Schedule::job(new ReleaseStaleReservations)->everyFifteenMinutes();

        /*
         * Aging debt, checked once a day after midnight — debt ages by the
         * calendar, so running it more often finds nothing new. The freeze
         * at four months needs no job at all: it is derived arithmetic,
         * recomputed by every credit check.
         */
        Schedule::job(new SweepDebtAging)->dailyAt('00:30');

        /*
         * Visit photos past their two-month retention, dropped nightly.
         * The visits themselves stay; only the files go. Admin and finance
         * archive a month as a zip before its photos reach this line.
         */
        Schedule::job(new PurgeVisitPhotos)->dailyAt('00:45');

        // Baskets untouched for three months. A cart holds quantities and
        // never money, so this deletes a shopping list, not a record.
        Schedule::job(new PruneAbandonedCarts)->dailyAt('01:00');

        /*
         * Do the ledgers still add up? Nightly, before the backup, so a dump
         * taken minutes later is one somebody has been told the truth about.
         *
         * Once a day rather than hourly: drift is caused by a write, not by
         * the passage of time, and a check that walks every SKU in every
         * region has no business running while people are ordering.
         */
        Schedule::job(new SweepLedgerIntegrity)->dailyAt('01:30');

        /*
         * Nightly backup, at an hour when nobody is ordering.
         *
         * `withoutOverlapping` because a dump of a large database can outrun
         * the day: two pg_dumps competing would make both slower and could
         * leave two runs writing the same night's artefacts.
         *
         * Deliberately **not** `runInBackground()`. The exit code is how a
         * failure becomes visible to whatever watches cron, and backgrounding
         * throws it away — which is how a backup that has not worked since
         * March keeps reporting nothing at all.
         */
        Schedule::command('backup:run')
            ->dailyAt('02:15')
            ->withoutOverlapping(60)
            ->onOneServer();
    }
}
