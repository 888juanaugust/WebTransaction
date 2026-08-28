<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Insight\ChartStats;
use Filament\Widgets\ChartWidget;

/**
 * What the shelf is worth, by category, at the same moving-average cost the
 * valuation and the neraca read — so the doughnut and the books agree.
 * Cost data, so it shows only to the roles that may see cost.
 */
class NilaiStokKategori extends ChartWidget
{
    protected static ?int $sort = 23;

    protected ?string $heading = 'Nilai stok per kategori';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canSeeCost() ?? false;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $grafik = app(ChartStats::class)->stockValueByCategory();

        return [
            'labels' => $grafik['labels'],
            'datasets' => [[
                'label' => 'Nilai (Rp)',
                'data' => $grafik['values'],
                'backgroundColor' => ['#2B3467', '#EB455F', '#BAD7E9', '#d97706', '#16a34a', '#6b7280'],
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']]];
    }
}
