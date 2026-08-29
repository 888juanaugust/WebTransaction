<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\ReportChart;
use App\Domain\Reporting\ReportCsv;
use App\Domain\Reporting\ReportTable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What every report screen has in common.
 *
 * One Blade, one download action, one role gate. Four pages each rendering
 * their own table would be four places for a money column to end up
 * left-aligned or a total to go missing.
 *
 * The subclass supplies a `ReportTable` and the controls above it; everything
 * below the controls is the same table every time, which is also what makes
 * the CSV a single implementation.
 */
abstract class ReportPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static \UnitEnum|string|null $navigationGroup = 'Laporan';

    protected string $view = 'filament.pages.laporan.report';

    abstract public function getReport(): ReportTable;

    /**
     * The charts drawn above the table, derived from the table already built.
     *
     * Takes the report as an argument rather than calling getReport() again:
     * some of these reports walk a year of invoices, and building one twice
     * per request to draw its own picture would be the report screens' first
     * performance bug. Empty charts are dropped here so no page has to ask.
     *
     * @return list<ReportChart>
     */
    public function chartsFor(ReportTable $report): array
    {
        return [];
    }

    /** @return list<ReportChart> */
    public function visibleCharts(ReportTable $report): array
    {
        return array_values(array_filter(
            $this->chartsFor($report),
            fn (ReportChart $chart) => ! $chart->isEmpty(),
        ));
    }

    /**
     * A Blade partial with this report's own controls, or null for none.
     *
     * The four reports are asked different questions — a month, a date, a
     * threshold — and forcing one control strip to serve all of them would
     * mean three of the four carry an input that does nothing.
     */
    public function controlsView(): ?string
    {
        return null;
    }

    /**
     * The control account this report's total must tie to, named in the
     * caption under the date control. Piutang for the receivables side;
     * the payables mirror overrides it.
     */
    public function akunKontrol(): string
    {
        return 'Piutang Usaha';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeReports() ?? false;
    }

    /** Whether margin columns exist for this reader at all. */
    protected function withCost(): bool
    {
        return auth()->user()?->role()->canSeeCost() ?? false;
    }

    /**
     * A filename somebody can find again in six months.
     *
     * Report and period, both, because these get downloaded monthly into one
     * folder and `laporan.csv` overwriting `laporan.csv` is how last month's
     * figures disappear.
     */
    public function unduh(): StreamedResponse
    {
        $report = $this->getReport();
        $csv = app(ReportCsv::class)->write($report);

        $name = str($report->judul.' '.$report->period->label)
            ->slug()
            ->append('.csv')
            ->value();

        return response()->streamDownload(fn () => print $csv, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('unduh')
                ->label('Unduh CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action('unduh'),
        ];
    }
}
