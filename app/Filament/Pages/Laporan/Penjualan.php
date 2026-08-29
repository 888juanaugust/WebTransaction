<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Regions\RegionContext;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportTable;
use App\Domain\Reporting\SalesDimension;
use App\Domain\Reporting\SalesReport;
use App\Models\Region;
use Illuminate\Support\Carbon;

/**
 * What we sold, grouped four ways.
 *
 * Defaults to last month by customer: the question somebody sits down with.
 * The current month would invite comparing a part-finished period against
 * whole ones and concluding sales have collapsed.
 */
class Penjualan extends ReportPage
{
    protected static ?string $navigationLabel = 'Penjualan';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'laporan/penjualan';

    public string $dari = '';

    public string $sampai = '';

    public string $dimensi = SalesDimension::Pelanggan->value;

    public function mount(): void
    {
        $default = Period::lastMonth();

        $this->dari = $this->dari ?: $default->from->toDateString();
        $this->sampai = $this->sampai ?: $default->to->toDateString();
    }

    public function getTitle(): string
    {
        return 'Laporan penjualan';
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.penjualan';
    }

    /** @return array<string, string> */
    public function getDimensiOptions(): array
    {
        return collect(SalesDimension::cases())
            ->mapWithKeys(fn (SalesDimension $d) => [$d->value => $d->label()])
            ->all();
    }

    public function getDimensi(): SalesDimension
    {
        return SalesDimension::from($this->dimensi);
    }

    public function getReport(): ReportTable
    {
        return app(SalesReport::class)->build(
            Period::between(Carbon::parse($this->dari), Carbon::parse($this->sampai)),
            $this->getDimensi(),
            withCost: $this->withCost(),
        );
    }

    public function chartsFor(ReportTable $report): array
    {
        $charts = [app(SalesReport::class)->chart($report, $this->getDimensi())];

        /*
         * Sales per wilayah, only for a viewer who already sees every region
         * — the Owner on "Semua wilayah", or marketing. For anyone pinned,
         * drawing other regions' sales here would be this page quietly
         * undoing the region scope.
         */
        if (app(RegionContext::class)->regionId() === null && Region::query()->count() > 1) {
            $charts[] = app(SalesReport::class)->regionChart($report->period);
        }

        return $charts;
    }
}
