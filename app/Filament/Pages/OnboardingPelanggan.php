<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Onboarding\KesiapanOnboarding;
use App\Domain\Onboarding\LangkahOnboarding;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The pilot worklist: every customer, and how far they are from actually
 * using the portal.
 *
 * Same shape as Kesiapan peluncuran, one level down: that screen asks
 * whether the business is ready to trade, this one asks whether one
 * customer is ready to order. Every row recomputes from real state — no
 * tickboxes — so it doubles as the daily pilot standup: open it, look at
 * what un-ticked overnight, go fix that.
 *
 * Visible to everyone who can see credit data. Sales work this list on
 * their own customers; the Owner works it across the pilot.
 */
class OnboardingPelanggan extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENJUALAN;

    protected static ?string $navigationLabel = 'Onboarding pelanggan';

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'onboarding-pelanggan';

    protected string $view = 'filament.pages.onboarding-pelanggan';

    public function getTitle(): string
    {
        return 'Onboarding pelanggan';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canSeeCreditData() ?? false;
    }

    /**
     * Least-ready first: the list is a worklist, and the row with the most
     * missing steps is the customer somebody should be calling today.
     * Suspended customers are excluded — they are not being onboarded,
     * they are being collected from.
     *
     * @return list<array{company: Company, langkah: list<LangkahOnboarding>, selesai: int}>
     */
    public function baris(): array
    {
        $kesiapan = app(KesiapanOnboarding::class);

        return KesiapanOnboarding::preload(
            Company::query()->where('status', '!=', Company::STATUS_SUSPENDED)
        )
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Company $company) => [
                'company' => $company,
                'langkah' => $kesiapan->langkah($company),
                'selesai' => $kesiapan->selesai($company),
            ])
            ->sortBy('selesai')
            ->values()
            ->all();
    }

    public function total(): int
    {
        return app(KesiapanOnboarding::class)->total();
    }

    public function urlPelanggan(Company $company): string
    {
        return CompanyResource::getUrl('edit', ['record' => $company]);
    }
}
