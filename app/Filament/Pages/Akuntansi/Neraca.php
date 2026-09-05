<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Accounting\BalanceSheet;
use App\Filament\Navigation\SidebarGroups;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * What the business owns and owes on one day.
 *
 * Read-only and dated, because that is what a balance sheet is. Nothing on
 * this page can change a figure — every number traces back to a journal entry
 * and every journal entry to a document, and the way to change one is to
 * correct the document.
 */
class Neraca extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::BUKU_BESAR;

    protected static ?string $navigationLabel = 'Neraca';

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'akuntansi/neraca';

    protected string $view = 'filament.pages.akuntansi.neraca';

    /** Bound to the date input. */
    public string $tanggal = '';

    public function mount(): void
    {
        $this->tanggal = Carbon::now()->toDateString();
    }

    public function getTitle(): string
    {
        return 'Neraca';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeBooks() ?? false;
    }

    public function getNeraca(): BalanceSheet
    {
        return BalanceSheet::asOf($this->asOf());
    }

    public function asOf(): Carbon
    {
        return rescue(
            fn () => Carbon::parse($this->tanggal),
            fn () => Carbon::now(),
            report: false,
        );
    }
}
