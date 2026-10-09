<?php

namespace App\Providers;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuRegistry;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\CashBank\GiroService;
use App\Domain\CashBank\Reconciler;
use App\Domain\Fulfilment\FulfilmentService;
use App\Domain\Inventory\Costing\Recoster;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Posting\DocumentGuard;
use App\Domain\Posting\PostingService;
use App\Domain\Sales\Contracts\Prices;
use App\Domain\Sales\ListPrices;
use App\Models\User;
use App\Modules\ModuleContext;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Preferensi::class);
        $this->app->scoped(HakAkses::class);
        $this->app->scoped(MenuRegistry::class);
        $this->app->singleton(PostingService::class);
        $this->app->singleton(DocumentGuard::class);
        $this->app->singleton(Recoster::class);
        $this->app->singleton(FulfilmentService::class);
        $this->app->singleton(ApprovalEngine::class);
        $this->app->singleton(GiroService::class);
        $this->app->bind(Prices::class, ListPrices::class); // the price source every selling line reads; an installation may bind its own
        $this->app->singleton(Reconciler::class);
        $this->app->scoped(ModuleRegistry::class, fn ($app) => new ModuleRegistry(
            array_merge((array) config('modules.modules', []), (array) config('client.modules', [])),
            $app->make(Preferensi::class),
        ));
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Every password a person sets (the Users screen, the profile page): twelve characters at least, and in
        // production not one found in public breach lists.
        Password::defaults(fn () => $this->app->isProduction() ? Password::min(12)->uncompromised() : Password::min(12));

        // A client's own migrations and translations live under app/Client
        // (see CLAUDE.md, the client layer); its strings override the base.
        if (is_dir(app_path('Client/database/migrations'))) {
            $this->loadMigrationsFrom(app_path('Client/database/migrations'));
        }
        if (is_dir(app_path('Client/lang'))) {
            $this->loadJsonTranslationsFrom(app_path('Client/lang'));
        }

        // Every module declares its models' names for the polymorphic columns
        // (audit_logs.document_type, the posting tables); the map is the union
        // of every module, on or off, so rows already written always resolve.
        $registry = $this->app->make(ModuleRegistry::class);
        Relation::enforceMorphMap($registry->morphMap());

        // Then each module wires what it adds to the posting layer: ledger
        // writers, blockers, fulfilment chains, the document types that may
        // wait for approval.
        $context = new ModuleContext(
            $this->app,
            $this->app->make(PostingService::class),
            $this->app->make(DocumentGuard::class),
            $this->app->make(FulfilmentService::class),
            $this->app->make(MenuRegistry::class),
            $this->app->make(ApprovalEngine::class),
        );
        foreach ($registry->all() as $module) {
            $module::boot($context);
        }

        // Each module's own console commands and schedule. Every module's are
        // registered (the database may not exist yet when a command boots);
        // a scheduled run happens only while its module is on.
        if ($this->app->runningInConsole()) {
            $this->commands(array_merge(...array_map(fn (string $module) => $module::commands(), $registry->all())));
        }
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) use ($registry): void {
            foreach ($registry->all() as $module) {
                $before = count($schedule->events());
                $module::schedule($schedule);
                foreach (array_slice($schedule->events(), $before) as $event) {
                    $event->when(fn (): bool => $registry->isEnabled($module::key()));
                }
            }
        });

        // Every ability on a model resolves through the access matrix: the
        // model's screen (MenuRegistry) and the right the ability maps to.
        Gate::before(function (User $user, string $ability, array $arguments) {
            $subject = $arguments[0] ?? null;
            $class = match (true) {
                $subject instanceof Model => $subject::class,
                is_string($subject) && class_exists($subject) => $subject,
                default => null,
            };
            if ($class === null) {
                return null;
            }

            $key = app(MenuRegistry::class)->menuKeyForModel($class);
            $hak = Hak::fromAbility($ability);
            if ($key === null || $hak === null) {
                return null;
            }

            return app(HakAkses::class)->allows($user, $key, $hak);
        });
    }
}
