<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Filament\Support\ErpResource;
use Filament\Facades\Filament;

/**
 * Which screen a model belongs to, so Gate checks on a model ("update", "delete")
 * resolve to that screen's rights. Built from the panel's resources; a model
 * without a resource of its own is registered explicitly in register().
 */
final class MenuRegistry
{
    /** @var array<class-string, ScreenKey>|null */
    private ?array $map = null;

    /** @var array<class-string, ScreenKey> */
    private array $explicit = [];

    /** @var array<string, ScreenKey>|null */
    private ?array $tables = null;

    public function register(string $modelClass, ScreenKey $key): void
    {
        $this->explicit[$modelClass] = $key;
        $this->map = null;
        $this->tables = null;
    }

    public function menuKeyForModel(string $modelClass): ?ScreenKey
    {
        return $this->map()[$modelClass] ?? null;
    }

    /** The screen whose records live in the table, if any. */
    public function menuKeyForTable(string $table): ?ScreenKey
    {
        if ($this->tables === null) {
            $this->tables = [];
            foreach ($this->map() as $model => $key) {
                $this->tables[(new $model)->getTable()] ??= $key;
            }
        }

        return $this->tables[$table] ?? null;
    }

    /** @return array<class-string, ScreenKey> */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = $this->explicit;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (is_subclass_of($resource, ErpResource::class)) {
                $map[$resource::getModel()] ??= $resource::menuKey();
            }
        }

        return $this->map = $map;
    }
}
