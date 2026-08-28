<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Insight\ChartStats;
use Filament\Widgets\ChartWidget;

/**
 * Money in against money out, monthly, straight from the journal — the
 * laba rugi as a shape. Reading the ledger rather than the documents means
 * this chart cannot disagree with the statements finance signs.
 */
class PendapatanVsBeban extends ChartWidget
{
    protected static ?int $sort = 24;

    protected ?string $heading = 'Pendapatan vs beban — 12 bulan';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canPostJournals() ?? false;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $grafik = app(ChartStats::class)->revenueVsExpense();

        return [
            'labels' => $grafik['labels'],
            'datasets' => [
                [
                    'label' => 'Pendapatan (Rp)',
                    'data' => $grafik['pendapatan'],
                    'backgroundColor' => '#2B3467',
                    'borderRadius' => 4,
                ],
                [
                    'label' => 'Beban (Rp)',
                    'data' => $grafik['beban'],
                    'backgroundColor' => '#EB455F',
                    'borderRadius' => 4,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true]]];
    }
}
