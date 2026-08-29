<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\ReportTable;
use App\Domain\Reporting\StockAgeing;

/**
 * What is on the shelf and not moving.
 *
 * Behind `canSeeCost()`: the whole report is about how much money is tied up,
 * and the value column is inventory cost.
 */
class PerputaranStok extends ReportPage
{
    protected static ?string $navigationLabel = 'Perputaran stok';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'laporan/perputaran-stok';

    /** Hide the long tail of cheap oddments, which is most of the rows. */
    public int $nilaiMinimal = 0;

    public function getTitle(): string
    {
        return 'Perputaran stok';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeCost() ?? false;
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.perputaran-stok';
    }

    public function getReport(): ReportTable
    {
        return app(StockAgeing::class)->build(minValue: max(0, $this->nilaiMinimal));
    }

    public function chartsFor(ReportTable $report): array
    {
        return [app(StockAgeing::class)->chart($report)];
    }
}
