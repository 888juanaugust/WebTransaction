<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use App\Domain\Insight\ChartStats;
use Filament\Widgets\ChartWidget;

/**
 * The buyer's own spend, month by month — the "how much have we been buying
 * from you this year" answer, on their landing screen instead of in a phone
 * call to their bookkeeper.
 */
class GrafikBelanja extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Belanja 12 bulan terakhir';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return auth('customer')->user()?->company_id !== null;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $grafik = app(ChartStats::class)->monthlySpend(auth('customer')->user()->company);

        return [
            'labels' => $grafik['labels'],
            'datasets' => [[
                'label' => 'Belanja (Rp)',
                'data' => $grafik['values'],
                'backgroundColor' => '#2B3467',
                'borderRadius' => 4,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
