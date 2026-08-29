<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\LapsedCustomers;
use App\Domain\Reporting\ReportTable;

/**
 * Customers who have stopped ordering.
 *
 * No controls: there is one question and it does not take parameters. The
 * threshold is each customer's own ordering interval, which is not a number
 * anybody should be tuning from a screen — a report you can adjust until it
 * says what you wanted is not evidence of anything.
 */
class PelangganPasif extends ReportPage
{
    protected static ?string $navigationLabel = 'Pelanggan pasif';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'laporan/pelanggan-pasif';

    public function getTitle(): string
    {
        return 'Pelanggan yang berhenti pesan';
    }

    public function getReport(): ReportTable
    {
        return app(LapsedCustomers::class)->build();
    }

    public function chartsFor(ReportTable $report): array
    {
        return [app(LapsedCustomers::class)->chart($report)];
    }

    /** A count worth seeing before opening the page. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = count(app(LapsedCustomers::class)->build()->rows);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
