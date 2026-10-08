<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Posting\Tags;
use App\Models\Company\Department;
use App\Models\Company\Project;
use App\Modules\ModuleRegistry;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

/**
 * The department and project pickers, present only while the Departments or
 * Projects module is on. A header's are the document's default; a line's
 * own, when given, wins. Reports filter by them (a department with the
 * departments under it).
 */
final class TagFields
{
    public static function departmentsOn(): bool
    {
        return app(ModuleRegistry::class)->isEnabled('departments');
    }

    public static function projectsOn(): bool
    {
        return app(ModuleRegistry::class)->isEnabled('projects');
    }

    /** @return list<Select> the header pickers */
    public static function header(): array
    {
        return array_values(array_filter([
            self::departmentsOn() ? self::department()->label(__('Department')) : null,
            self::projectsOn() ? self::project()->label(__('Project')) : null,
        ]));
    }

    /** @return list<TableColumn> the line table's columns, matching lineFields() */
    public static function columns(): array
    {
        return array_values(array_filter([
            self::departmentsOn() ? TableColumn::make(__('Department')) : null,
            self::projectsOn() ? TableColumn::make(__('Project')) : null,
        ]));
    }

    /** @return list<Select> the line pickers; empty takes the header's */
    public static function lineFields(): array
    {
        return array_values(array_filter([
            self::departmentsOn() ? self::department()->placeholder(__('As the header')) : null,
            self::projectsOn() ? self::project()->placeholder(__('As the header')) : null,
        ]));
    }

    /** @return list<Select> the report filters */
    public static function filters(): array
    {
        return array_values(array_filter([
            self::departmentsOn() ? self::department()->label(__('Department'))->placeholder(__('All departments'))->live() : null,
            self::projectsOn() ? self::project()->label(__('Project'))->placeholder(__('All projects'))->live() : null,
        ]));
    }

    /** @return array{department_id: ?int, project_id: ?int} a source document's header tags, for the document made from it */
    public static function from(Model $source): array
    {
        return Tags::of($source)->toArray();
    }

    /** A picked filter value as an id, or null for all (and when the module is off). */
    public static function picked(mixed $value, string $module): ?int
    {
        if ($value === null || $value === '' || ! app(ModuleRegistry::class)->isEnabled($module)) {
            return null;
        }

        return (int) $value;
    }

    private static function department(): Select
    {
        return Select::make('department_id')->label(__('Department'))
            ->options(fn ($state) => Department::options() + ($state ? Department::query()->whereKey($state)->get()->mapWithKeys(fn (Department $d) => [$d->id => "{$d->code} · {$d->name}"])->all() : []))
            ->searchable()->native(false)->nullable();
    }

    private static function project(): Select
    {
        return Select::make('project_id')->label(__('Project'))
            ->options(fn ($state) => Project::options() + ($state ? Project::query()->whereKey($state)->get()->mapWithKeys(fn (Project $p) => [$p->id => "{$p->code} · {$p->name}"])->all() : []))
            ->searchable()->native(false)->nullable();
    }
}
