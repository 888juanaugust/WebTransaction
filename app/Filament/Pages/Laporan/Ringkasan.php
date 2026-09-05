<?php

declare(strict_types=1);

namespace App\Filament\Pages\Laporan;

use App\Domain\Reporting\Period;
use App\Domain\Reporting\Ringkasan as RingkasanData;
use App\Domain\Reporting\RingkasanBulanan;
use App\Filament\Navigation\SidebarGroups;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * The owner's month on one sheet.
 *
 * Owner only — this is the one screen where margin, cash and every
 * customer's debt sit side by side, which is exactly the combination the
 * role matrix splits between three other roles. Anyone with a narrower job
 * has a narrower report for it under the same menu.
 *
 * Defaults to last month, because a part-finished month next to whole ones
 * reads as a collapse. The position figures (piutang and its ageing) are
 * "as of today" whatever month is chosen, and the screen says so.
 */
class Ringkasan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::LAPORAN;

    protected static ?string $navigationLabel = 'Ringkasan bulanan';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'laporan/ringkasan';

    protected string $view = 'filament.pages.laporan.ringkasan';

    /** `Y-m`, bound to the month select. */
    public string $bulan = '';

    public function mount(): void
    {
        $this->bulan = Carbon::now()->subMonthNoOverflow()->format('Y-m');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canViewAuditLog() ?? false;
    }

    public function getTitle(): string
    {
        return 'Ringkasan bulanan';
    }

    public function ringkasan(): RingkasanData
    {
        return app(RingkasanBulanan::class)->build(Period::month($this->bulan));
    }

    /**
     * The last twelve whole months, newest first — the months an owner
     * actually revisits. Anything older lives in the detail reports.
     *
     * @return array<string, string>
     */
    public function pilihanBulan(): array
    {
        $pilihan = [];
        $cursor = Carbon::now()->subMonthNoOverflow()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $pilihan[$cursor->format('Y-m')] = $cursor->translatedFormat('F Y');
            $cursor->subMonthNoOverflow();
        }

        return $pilihan;
    }
}
