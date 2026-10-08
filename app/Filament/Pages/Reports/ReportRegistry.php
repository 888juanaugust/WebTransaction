<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use Illuminate\Support\Collection;

/** The catalogue's contents: every report page, found by class so a new report registers itself. */
final class ReportRegistry
{
    /** @return Collection<int, class-string<ReportPage>> the reports whose module is on */
    public static function all(): Collection
    {
        return self::every()->filter(fn (string $class) => $class::available())->values();
    }

    /** @return Collection<int, class-string<ReportPage>> every report, whatever is switched on */
    public static function every(): Collection
    {
        $classes = [];
        foreach (glob(app_path('Filament/Pages/Reports/*.php')) ?: [] as $file) {
            $class = 'App\\Filament\\Pages\\Reports\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, ReportPage::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return collect($classes)->sortBy(fn (string $class) => [self::groupOrder($class::group()), $class::title()])->values();
    }

    /** @return Collection<string, Collection<int, class-string<ReportPage>>> group → reports */
    public static function grouped(?string $search = null): Collection
    {
        return self::all()
            ->filter(fn (string $class) => $search === null || $search === '' || str_contains(strtolower($class::title().' '.$class::description()), strtolower($search)))
            ->groupBy(fn (string $class) => $class::group());
    }

    private static function groupOrder(string $group): int
    {
        return array_search($group, ['Financial', 'Sales', 'Purchasing', 'Inventory', 'Cash & Bank', 'Fixed Assets'], true) ?: 99;
    }
}
