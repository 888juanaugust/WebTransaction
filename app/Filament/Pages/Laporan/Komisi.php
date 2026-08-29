<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\KomisiReport;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\ReportTable;
use Illuminate\Support\Carbon;

/**
 * Komisi & target per bulan — who earned what on the money that arrived.
 *
 * Owner and Finance only: what colleagues earn is payroll-adjacent, and the
 * seats that negotiate with customers should not be reading each other's
 * commission. The rates and targets behind it are set on the Owner's
 * "Komisi & target" screen; this page only ever reads.
 */
class Komisi extends ReportPage
{
    protected static ?string $navigationLabel = 'Komisi & target';

    protected static ?int $navigationSort = 26;

    protected static ?string $slug = 'laporan/komisi';

    public string $bulan = '';

    public function mount(): void
    {
        // Last month: the month being paid out is the one that just closed.
        $this->bulan = Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Komisi & target';
    }

    public static function canAccess(): bool
    {
        $role = auth()->user()?->role();

        return $role?->canConfirmPayment() ?? false;
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.per-bulan';
    }

    public function keteranganBulan(): string
    {
        return 'Bulan pelunasan, bukan bulan terbit faktur — komisi mengikuti uang yang '
            .'benar-benar masuk.';
    }

    public function getReport(): ReportTable
    {
        return app(KomisiReport::class)->build(Period::month($this->bulan));
    }

    public function chartsFor(ReportTable $report): array
    {
        return [];
    }
}
