<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\Period;
use App\Domain\Reporting\RekapPpn;
use App\Domain\Reporting\ReportTable;
use Illuminate\Support\Carbon;

/**
 * Keluaran − Masukan for one masa pajak — the number the accountant files.
 *
 * Behind `canExportFaktur()`: whoever files the output VAT return is the
 * person this sheet exists for, and nobody else needs the netting.
 */
class RekapPpnMasa extends ReportPage
{
    protected static ?string $navigationLabel = 'Rekap PPN';

    protected static ?int $navigationSort = 27;

    protected static ?string $slug = 'laporan/rekap-ppn';

    public string $bulan = '';

    public function mount(): void
    {
        $this->bulan = Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Rekap PPN masa';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canExportFaktur() ?? false;
    }

    public function controlsView(): ?string
    {
        return 'filament.pages.laporan.kontrol.per-bulan';
    }

    public function keteranganBulan(): string
    {
        return 'Masa pajak: keluaran dari faktur terbit bulan itu, masukan dari tanggal '
            .'faktur pemasok.';
    }

    public function getReport(): ReportTable
    {
        return app(RekapPpn::class)->build(Period::month($this->bulan));
    }

    public function chartsFor(ReportTable $report): array
    {
        return [];
    }
}
