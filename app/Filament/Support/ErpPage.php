<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\ScreenKey;
use App\Modules\ModuleRegistry;
use Filament\Pages\Page;
use Filament\Panel;
use UnitEnum;

/** A standalone screen (settings, inquiry, report): placed, named and guarded like a resource. */
abstract class ErpPage extends Page
{
    abstract public static function menuKey(): ScreenKey;

    public static function canAccess(): bool
    {
        return static::moduleEnabled() && app(HakAkses::class)->allows(auth()->user(), static::menuKey(), Hak::View);
    }

    /** Whether the module that owns this screen is switched on. */
    public static function moduleEnabled(): bool
    {
        return app(ModuleRegistry::class)->menuKeyEnabled(static::menuKey());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::moduleEnabled();
    }

    /** Whether the current user may change what this screen shows. */
    public static function canUpdate(): bool
    {
        return app(HakAkses::class)->allows(auth()->user(), static::menuKey(), Hak::Update);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::menuKey()->modul();
    }

    public static function getNavigationSort(): ?int
    {
        return static::menuKey()->sort();
    }

    public static function getNavigationLabel(): string
    {
        return static::menuKey()->label();
    }

    public function getTitle(): string
    {
        return static::menuKey()->label();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return static::menuKey()->slug();
    }
}
