<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Access\ScreenKey;
use App\Domain\Pengaturan\PreferensiKey;
use Illuminate\Console\Scheduling\Schedule;

/** Empty defaults, so a module declares only what it has. */
abstract class BaseModule implements Module
{
    public static function feature(): ?PreferensiKey
    {
        return null;
    }

    public static function featureForKey(ScreenKey $key): ?PreferensiKey
    {
        return static::feature();
    }

    public static function morphMap(): array
    {
        return [];
    }

    public static function boot(ModuleContext $context): void {}

    public static function defaultSeeders(): array
    {
        return [];
    }

    public static function commands(): array
    {
        return [];
    }

    public static function schedule(Schedule $schedule): void {}
}
