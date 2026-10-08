<?php

declare(strict_types=1);

namespace App\Filament\Shell;

use App\Domain\Access\Screens;
use App\Filament\Modul;
use App\Filament\Pages\Reports\ReportPage;
use App\Filament\Support\ErpPage;
use App\Filament\Support\ErpResource;
use Filament\Facades\Filament;

/**
 * The module menu of the workspace shell: one group per module, one tile per
 * screen the current user can reach, in the standard's order. A screen is
 * reachable when its class is registered, its module is switched on and the
 * user's rights let them view it (ErpResource / ErpPage decide both).
 */
final class Menu
{
    /** @return array<string, class-string> menu key value → the screen's resource or page class */
    public static function screens(): array
    {
        $panel = Filament::getPanel('admin');
        $screens = [];
        foreach ($panel->getResources() as $resource) {
            if (is_subclass_of($resource, ErpResource::class)) {
                $screens[$resource::menuKey()->value] ??= $resource;
            }
        }
        // Every report page shares the catalogue's key; the catalogue itself is the tile.
        $pages = collect($panel->getPages())->filter(fn (string $page) => is_subclass_of($page, ErpPage::class))
            ->sortBy(fn (string $page) => is_subclass_of($page, ReportPage::class) ? 1 : 0);
        foreach ($pages as $page) {
            $screens[$page::menuKey()->value] ??= $page;
        }

        return $screens;
    }

    /**
     * @return list<array{key: string, label: string, icon: string, tiles: list<array{key: string, label: string, url: string, icon: mixed, kind: string}>}>
     */
    public static function forUser(): array
    {
        $screens = self::screens();
        $groups = [];
        foreach (Modul::cases() as $modul) {
            $keys = Screens::of($modul);
            $tiles = [];
            foreach ($keys as $key) {
                $class = $screens[$key->value] ?? null;
                if ($class === null || ! $class::canAccess()) {
                    continue;
                }
                $tiles[] = [
                    'key' => $key->value,
                    'label' => $key->label(),
                    'url' => self::url($class),
                    'icon' => $class::getNavigationIcon(),
                    'kind' => $key->kind()->value,
                ];
            }
            if ($tiles !== []) {
                $groups[] = ['key' => $modul->value, 'label' => $modul->getLabel(), 'icon' => $modul->getIcon(), 'tiles' => $tiles];
            }
        }

        return $groups;
    }

    /** @return array<string, string> path → menu key value, for every screen the user may open, so a framed page can tell one screen from another */
    public static function paths(): array
    {
        $paths = [];
        foreach (self::screens() as $key => $class) {
            if (! $class::canAccess()) {
                continue; // the browser learns only the screens it can reach
            }
            $paths[self::path(self::url($class))] = $key;
        }

        return $paths;
    }

    public static function path(string $url): string
    {
        return rtrim((string) parse_url($url, PHP_URL_PATH), '/') ?: '/';
    }

    /** @param  class-string  $class */
    private static function url(string $class): string
    {
        return is_subclass_of($class, ErpResource::class) ? $class::getUrl('index') : $class::getUrl();
    }
}
