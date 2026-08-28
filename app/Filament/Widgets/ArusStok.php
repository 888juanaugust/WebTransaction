<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Insight\ChartStats;
use Filament\Widgets\ChartWidget;

/**
 * Goods in against goods out, per day for a month — the pulse of the
 * warehouse. Receipts drying up while shipments carry on is the reorder
 * point about to fire everywhere at once; the reverse is a shelf filling
 * with money.
 */
class ArusStok extends ChartWidget
{
    protected static ?int $sort = 22;

    protected ?string $heading = 'Arus stok 30 hari — masuk vs keluar';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        // The people who move the cartons, and the Owner: transfer rights
        // are the cleanest "works the warehouse" signal the role enum has.
        return auth()->user()?->role()->canTransferStock() ?? false;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $grafik = app(ChartStats::class)->stockFlow();

        return [
            'labels' => $grafik['labels'],
            'datasets' => [
                [
                    'label' => 'Masuk (unit)',
                    'data' => $grafik['masuk'],
                    'borderColor' => '#2B3467',
                    'backgroundColor' => 'rgba(43, 52, 103, .12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Keluar (unit)',
                    'data' => $grafik['keluar'],
                    'borderColor' => '#EB455F',
                    'backgroundColor' => 'rgba(235, 69, 95, .10)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true]]];
    }
}
