<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Accounting\ProfitAndLoss;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * What the business earned between two dates.
 *
 * Defaults to the year so far rather than to the current month, because the
 * question staff actually ask this screen is "how are we doing", and one month
 * of a business that trades in lumpy wholesale orders answers it badly.
 */
class LabaRugi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static \UnitEnum|string|null $navigationGroup = 'Buku besar';

    protected static ?string $navigationLabel = 'Laba rugi';

    protected static ?int $navigationSort = 72;

    protected static ?string $slug = 'akuntansi/laba-rugi';

    protected string $view = 'filament.pages.akuntansi.laba-rugi';

    public string $dari = '';

    public string $sampai = '';

    public function mount(): void
    {
        $this->dari = Carbon::now()->startOfYear()->toDateString();
        $this->sampai = Carbon::now()->toDateString();
    }

    public function getTitle(): string
    {
        return 'Laba rugi';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeBooks() ?? false;
    }

    public function getLabaRugi(): ProfitAndLoss
    {
        return ProfitAndLoss::forPeriod($this->from(), $this->to());
    }

    public function from(): Carbon
    {
        return $this->parse($this->dari, Carbon::now()->startOfYear());
    }

    /**
     * A period that runs backwards would silently report nothing at all, which
     * reads exactly like a quiet month. Clamped instead.
     */
    public function to(): Carbon
    {
        $to = $this->parse($this->sampai, Carbon::now());

        return $to->lessThan($this->from()) ? $this->from() : $to;
    }

    private function parse(string $value, Carbon $fallback): Carbon
    {
        return rescue(fn () => Carbon::parse($value), fn () => $fallback, report: false);
    }
}
