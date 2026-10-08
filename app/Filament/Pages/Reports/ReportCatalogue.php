<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Access\MenuKey;
use App\Filament\Support\ErpPage;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Report Catalogue: every report by group, searched by name; each opens with its own filters and exports to Excel. */
class ReportCatalogue extends ErpPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected string $view = 'filament.pages.reports.catalogue';

    public string $search = '';

    public static function menuKey(): MenuKey
    {
        return MenuKey::ReportCatalogue;
    }

    /** @return Collection<string, Collection<int, class-string<ReportPage>>> group → reports matching the search */
    public function groups(): Collection
    {
        return ReportRegistry::grouped($this->search);
    }
}
