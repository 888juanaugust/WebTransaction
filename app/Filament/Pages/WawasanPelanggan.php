<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Role;
use App\Domain\Insight\CustomerInsight;
use App\Models\Company;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The visit-preparation screen: one customer's buying story on one page.
 *
 * A sales opens it before walking into the store — recent orders, the
 * catalogue items the rest of the market buys that this store never has,
 * and the items the store used to buy and quietly stopped. The last two
 * are the agenda; the first is the context.
 *
 * Scoped like everything else the team sees: a sales chooses among their
 * own customers, a marketing among theirs, the Owner among all.
 */
class WawasanPelanggan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static ?string $navigationLabel = 'Pelanggan saya';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'pelanggan-saya';

    protected string $view = 'filament.pages.wawasan-pelanggan';

    public ?int $companyId = null;

    public function getTitle(): string
    {
        return 'Pelanggan saya';
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role(), [Role::Sales, Role::Marketing, Role::Owner], true);
    }

    /** @return array<int, string> */
    public function companyOptions(): array
    {
        $user = auth()->user();

        $query = Company::query()->where('status', Company::STATUS_ACTIVE);

        $query = match ($user?->role()) {
            Role::Sales => $query->managedBySales($user),
            Role::Marketing => $query->managedByMarketing($user),
            default => $query,
        };

        return $query->orderBy('nama')->pluck('nama', 'id')->all();
    }

    protected function getViewData(): array
    {
        $company = $this->companyId !== null
            && array_key_exists($this->companyId, $this->companyOptions())
                ? Company::query()->find($this->companyId)
                : null;

        if ($company === null) {
            return ['company' => null, 'riwayat' => collect(), 'belumPernah' => collect(), 'berhenti' => collect()];
        }

        $insight = app(CustomerInsight::class);

        return [
            'company' => $company,
            'riwayat' => $insight->history($company),
            'belumPernah' => $insight->neverBought($company),
            'berhenti' => $insight->stoppedBuying($company),
        ];
    }
}
