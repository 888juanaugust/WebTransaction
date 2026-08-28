<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Access\Role;
use App\Domain\Insight\ChartStats;
use Filament\Widgets\ChartWidget;

/**
 * Who owes the most, right now. For a marketing this is their own debt
 * watch — the customers they answer for, largest exposure first; finance
 * and the Owner read the whole region. The receivables-aging report has
 * the detail; this is the shape of it at a glance.
 */
class PiutangPerPelanggan extends ChartWidget
{
    protected static ?int $sort = 21;

    protected ?string $heading = 'Piutang berjalan per pelanggan';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role(), [Role::Marketing, Role::Finance, Role::Owner], true);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $grafik = app(ChartStats::class)->outstandingByCustomer(auth()->user());

        return [
            'labels' => $grafik['labels'],
            'datasets' => [[
                'label' => 'Sisa tagihan (Rp)',
                'data' => $grafik['values'],
                'backgroundColor' => '#EB455F',
                'borderRadius' => 4,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            // Horizontal bars: customer names need the room more than the
            // rupiah axis does.
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true]],
        ];
    }
}
