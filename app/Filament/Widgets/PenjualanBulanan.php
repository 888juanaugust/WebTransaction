<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Access\Role;
use App\Domain\Insight\ChartStats;
use Filament\Widgets\ChartWidget;

/**
 * Committed sales by month, seen through the viewer's own seat: a sales
 * reads the orders on their name, a marketing their customers' orders
 * whoever sold them, the Owner everything. One widget, three honest
 * answers — the same scoping rule as the queues above it.
 */
class PenjualanBulanan extends ChartWidget
{
    protected static ?int $sort = 20;

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role(), [Role::Sales, Role::Marketing, Role::Owner], true);
    }

    public function getHeading(): string
    {
        return match (auth()->user()?->role()) {
            Role::Sales => 'Penjualan saya per bulan',
            Role::Marketing => 'Penjualan pelanggan saya per bulan',
            default => 'Penjualan per bulan',
        };
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $grafik = app(ChartStats::class)->monthlySales(auth()->user());

        return [
            'labels' => $grafik['labels'],
            'datasets' => [[
                'label' => 'Penjualan (Rp)',
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
