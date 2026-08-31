<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\KpiReport;
use App\Domain\Reporting\KpiSubjek;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportTable;
use Illuminate\Support\Carbon;

/**
 * KPI per sales, per toko, per barang.
 *
 * Defaults to last month per sales: the sheet somebody opens on the first of
 * the month to see where the team stands. The current month would invite
 * comparing a part-finished period against a whole month's target and
 * concluding everybody missed.
 *
 * Open to every reader of reports, Sales included — deliberately. A sales
 * seat has to be able to see their own coverage and their own collection, and
 * nothing here carries cost, so no margin leaks through it.
 */
class Kpi extends ReportPage
{
    protected static ?string $navigationLabel = 'KPI';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'laporan/kpi';

    public string $dari = '';

    public string $sampai = '';

    public string $subjek = KpiSubjek::Sales->value;

    public function mount(): void
    {
        $default = Period::lastMonth();

        $this->dari = $this->dari ?: $default->from->toDateString();
        $this->sampai = $this->sampai ?: $default->to->toDateString();
    }

    public function getTitle(): string
    {
        return 'Laporan KPI';
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.kpi';
    }

    /** @return array<string, string> */
    public function getSubjekOptions(): array
    {
        return collect(KpiSubjek::cases())
            ->mapWithKeys(fn (KpiSubjek $s) => [$s->value => $s->label()])
            ->all();
    }

    public function getSubjek(): KpiSubjek
    {
        return KpiSubjek::from($this->subjek);
    }

    public function getReport(): ReportTable
    {
        return app(KpiReport::class)->build(
            Period::between(Carbon::parse($this->dari), Carbon::parse($this->sampai)),
            $this->getSubjek(),
        );
    }

    public function chartsFor(ReportTable $report): array
    {
        return [app(KpiReport::class)->chart($report, $this->getSubjek())];
    }
}
