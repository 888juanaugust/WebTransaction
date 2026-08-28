<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use App\Domain\Insight\ChartStats;
use Filament\Widgets\ChartWidget;

/**
 * Outstanding debt by age, on the organisation's own lines — so the customer
 * can see the 150-day cliff before their account walks off it. The last bar
 * turning red is the banner above it becoming literal.
 */
class GrafikUmurTagihan extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Umur tagihan terbuka';

    protected ?string $description = 'Melewati 150 hari, akun otomatis terkunci dari pemesanan baru.';

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
        $grafik = app(ChartStats::class)->debtAgeBuckets(auth('customer')->user()->company);

        return [
            'labels' => $grafik['labels'],
            'datasets' => [[
                'label' => 'Sisa tagihan (Rp)',
                'data' => $grafik['values'],
                // Green while inside the term, warmer as it ages, red at the
                // freeze — the same story the portal banner tells in words.
                'backgroundColor' => ['#16a34a', '#d97706', '#ea580c', '#EB455F'],
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
