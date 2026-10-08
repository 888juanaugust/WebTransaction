<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Access\ScreenKey;
use App\Modules\ModuleRegistry;
use Filament\Panel;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Every resource of the product: it is one screen of the standard's
 * menu (its MenuKey), sits in that screen's module at that screen's position,
 * and is named in English from lang/en/menu.php. Access is decided by the
 * access matrix, not by per-model policies; a user limited to some branches
 * sees only their branches' records (and those of no branch).
 */
abstract class ErpResource extends Resource
{
    protected static bool $shouldCheckPolicyExistence = false;

    abstract public static function menuKey(): ScreenKey;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $ability = $action instanceof UnitEnum ? ($action->value ?? $action->name) : $action;
        $hak = Hak::fromAbility((string) $ability);

        if ($hak === null) {
            return Response::deny();
        }
        if (! static::moduleEnabled()) {
            return Response::deny(__('This module is switched off in Preferences.'));
        }

        return app(HakAkses::class)->allows(auth()->user(), static::menuKey(), $hak)
            ? Response::allow()
            : Response::deny();
    }

    public static function getEloquentQuery(): Builder
    {
        return BranchLimit::apply(parent::getEloquentQuery(), auth()->user());
    }

    public static function canPrint(): bool
    {
        return static::moduleEnabled() && app(HakAkses::class)->allows(auth()->user(), static::menuKey(), Hak::Print);
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

    public static function getModelLabel(): string
    {
        // The English model label is a key of lang/<locale>.json (tools/i18n/extract-strings.mjs collects it).
        return static::$modelLabel !== null ? __(static::$modelLabel) : static::menuKey()->label();
    }

    public static function getPluralModelLabel(): string
    {
        return static::$pluralModelLabel !== null ? __(static::$pluralModelLabel) : static::menuKey()->label();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return static::menuKey()->slug();
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return static::$recordTitleAttribute ?? 'name';
    }
}
