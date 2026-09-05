<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets;

use App\Domain\Assets\DepreciationRunner;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Resources\FixedAssets\Tables\FixedAssetsTable;
use App\Models\FixedAsset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Aktiva tetap — what the business owns and uses rather than sells.
 *
 * The register is the subledger behind two control accounts, so nothing here
 * is editable: a cost that could be changed after the fact would put Aktiva
 * Tetap out against the ledger with no record of who moved it. Assets are
 * registered, depreciated, and eventually disposed of.
 */
class FixedAssetResource extends Resource
{
    protected static ?string $model = FixedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::BUKU_BESAR;

    protected static ?string $navigationLabel = 'Aktiva tetap';

    protected static ?string $modelLabel = 'aktiva tetap';

    protected static ?string $pluralModelLabel = 'aktiva tetap';

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'aktiva-tetap';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canPostJournals() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Months owing depreciation that nobody has run.
     *
     * The only thing here worth interrupting somebody for. Depreciation is a
     * job somebody triggers, and a month nobody ran is a laba rugi that
     * overstates profit — silently, because nothing else in the system has any
     * reason to mention it.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $outstanding = count(app(DepreciationRunner::class)->outstandingPeriods());

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return FixedAssetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssets::route('/'),
        ];
    }
}
