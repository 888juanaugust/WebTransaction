<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Access\ScreenKey;
use App\Domain\Pengaturan\PreferensiKey;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * One standard module of the ERP: the screens it owns, the preference that
 * switches it on (null when it is part of the core), the models it names in
 * the polymorphic columns, and what it wires into the posting layer. A module
 * is declared in config/modules.php; the registry does the rest.
 */
interface Module
{
    /** The key a client refers to the module by: 'fixed-assets'. */
    public static function key(): string;

    /** The Features preference that enables the module, or null when it is always on. */
    public static function feature(): ?PreferensiKey;

    /** @return list<ScreenKey> the screens this module owns */
    public static function menuKeys(): array;

    /** The preference that enables one screen, when it differs from the module's own. */
    public static function featureForKey(ScreenKey $key): ?PreferensiKey;

    /** @return array<string, class-string<Model>> morph alias → model */
    public static function morphMap(): array;

    /** Ledger writers, blockers, fulfilment chains and explicit menu registrations. */
    public static function boot(ModuleContext $context): void;

    /** @return list<class-string<Seeder>> the defaults a company starts with */
    public static function defaultSeeders(): array;

    /** @return list<class-string<Command>> */
    public static function commands(): array;

    public static function schedule(Schedule $schedule): void;
}
