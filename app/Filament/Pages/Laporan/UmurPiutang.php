<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\ReceivablesAgeing;
use App\Domain\Reporting\ReportTable;
use Illuminate\Support\Carbon;

/**
 * Who owes what, by age.
 *
 * Behind `canSeeCreditData()`, which includes Sales — deliberately. They see a
 * customer's remaining credit when placing an order anyway, and they are
 * usually the ones who ring about an overdue invoice. What Sales are kept away
 * from is **cost**, and there is none on this report.
 */
class UmurPiutang extends ReportPage
{
    protected static ?string $navigationLabel = 'Umur piutang';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'laporan/umur-piutang';

    public string $perTanggal = '';

    public function mount(): void
    {
        $this->perTanggal = $this->perTanggal ?: Carbon::now()->toDateString();
    }

    public function getTitle(): string
    {
        return 'Umur piutang';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.per-tanggal';
    }

    public function getReport(): ReportTable
    {
        return app(ReceivablesAgeing::class)->build(Carbon::parse($this->perTanggal));
    }

    public function chartsFor(ReportTable $report): array
    {
        return [app(ReceivablesAgeing::class)->chart($report)];
    }
}
