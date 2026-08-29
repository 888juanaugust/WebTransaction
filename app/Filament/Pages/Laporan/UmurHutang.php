<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\PayablesAgeing;
use App\Domain\Reporting\ReportTable;
use Illuminate\Support\Carbon;

/**
 * Whom we owe, by age — the Monday-morning payment run's worklist.
 *
 * Behind `canRecordPurchases()` (Finance and Owner): what we owe suppliers
 * is purchase-side money, the same boundary as the bills themselves. Sales
 * and Marketing negotiate what customers pay; what we pay is not their
 * lever, for the same reason cost is not.
 */
class UmurHutang extends ReportPage
{
    protected static ?string $navigationLabel = 'Umur hutang';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'laporan/umur-hutang';

    public string $perTanggal = '';

    public function mount(): void
    {
        $this->perTanggal = $this->perTanggal ?: Carbon::now()->toDateString();
    }

    public function getTitle(): string
    {
        return 'Umur hutang';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canRecordPurchases() ?? false;
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.per-tanggal';
    }

    public function akunKontrol(): string
    {
        return 'Hutang Usaha';
    }

    public function getReport(): ReportTable
    {
        return app(PayablesAgeing::class)->build(Carbon::parse($this->perTanggal));
    }

    public function chartsFor(ReportTable $report): array
    {
        return [app(PayablesAgeing::class)->chart($report)];
    }
}
