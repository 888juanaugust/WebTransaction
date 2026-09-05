<?php

declare(strict_types=1);

namespace App\Filament\Pages\Akuntansi;

use App\Domain\Accounting\ControlAccountCheck;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Accounting\TrialBalance;
use App\Domain\Accounting\TrialBalanceRow;
use App\Filament\Navigation\SidebarGroups;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Neraca saldo, and the check that matters more than it does.
 *
 * The trial balance proves the ledger is internally consistent. It cannot
 * prove the postings were right — a rule that credits the wrong account
 * balances perfectly and is still wrong — so the control accounts are on the
 * same screen, each against the subledger it summarises. That is the panel to
 * look at, and it is why this page exists rather than only the two statements.
 */
class NeracaSaldo extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::BUKU_BESAR;

    protected static ?string $navigationLabel = 'Neraca saldo';

    protected static ?int $navigationSort = 73;

    protected static ?string $slug = 'akuntansi/neraca-saldo';

    protected string $view = 'filament.pages.akuntansi.neraca-saldo';

    public string $tanggal = '';

    /** Accounts with no movement are noise on a chart this small, but not always. */
    public bool $sembunyikanKosong = true;

    public function mount(): void
    {
        $this->tanggal = Carbon::now()->toDateString();
    }

    public function getTitle(): string
    {
        return 'Neraca saldo';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeBooks() ?? false;
    }

    /**
     * A red badge in the sidebar when a control account has drifted.
     *
     * Silent drift is the whole failure mode here: the subledgers stay right
     * and keep running the business, so nothing breaks and nobody looks. This
     * is the thing that makes somebody look.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = count(app(LedgerReconciliation::class)->discrepancies());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTrialBalance(): TrialBalance
    {
        return TrialBalance::asOf($this->asOf());
    }

    /** @return list<TrialBalanceRow> */
    public function getRows(): array
    {
        $tb = $this->getTrialBalance();

        return $this->sembunyikanKosong ? $tb->rowsWithActivity() : $tb->rows();
    }

    /** @return list<ControlAccountCheck> */
    public function getChecks(): array
    {
        return app(LedgerReconciliation::class)->checks();
    }

    public function asOf(): Carbon
    {
        return rescue(fn () => Carbon::parse($this->tanggal), fn () => Carbon::now(), report: false);
    }
}
