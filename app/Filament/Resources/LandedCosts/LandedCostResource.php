<?php

declare(strict_types=1);

namespace App\Filament\Resources\LandedCosts;

use App\Filament\Resources\LandedCosts\Pages\ListLandedCosts;
use App\Filament\Resources\LandedCosts\Pages\ViewLandedCost;
use App\Filament\Resources\LandedCosts\Schemas\LandedCostDetail;
use App\Filament\Resources\LandedCosts\Tables\LandedCostsTable;
use App\Models\LandedCost;
use App\Models\SupplierBillLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Biaya perolehan — freight and duty landing on the goods they belong to.
 *
 * No create page and no edit page, deliberately. The lines are drawn from the
 * receipts by LandedCostAllocator, and a form that let somebody type the
 * shares by hand would turn the document from a record of a rule being applied
 * into a place to hide a number.
 *
 * A wrong draft is deleted and drawn again; a wrong posting is corrected by
 * another document, like every other money document here.
 */
class LandedCostResource extends Resource
{
    protected static ?string $model = LandedCost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Biaya perolehan';

    protected static ?string $modelLabel = 'biaya perolehan';

    protected static ?string $pluralModelLabel = 'biaya perolehan';

    protected static \UnitEnum|string|null $navigationGroup = 'Pembelian';

    protected static ?int $navigationSort = 36;

    protected static ?string $slug = 'biaya-perolehan';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canAllocateLandedCost() ?? false;
    }

    /** Drawn from the receipts, not typed. See the list page's action. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** A draft can be thrown away and drawn again. A posted one cannot. */
    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && $record->isDraft();
    }

    /**
     * Charges billed to us that nobody has spread yet.
     *
     * The same population the clearing account's control check measures, so a
     * badge here and a balance on the neraca move together — which is the
     * point of both.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $waiting = SupplierBillLine::query()
            ->where('supplier_bill_lines.jenis', SupplierBillLine::JENIS_BIAYA)
            ->whereHas('supplierBill', fn ($q) => $q
                ->whereNotNull('posted_at')
                ->where('status', '!=', 'void'))
            ->whereDoesntHave('landedCost')
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return LandedCostsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LandedCostDetail::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLandedCosts::route('/'),
            'view' => ViewLandedCost::route('/{record}'),
        ];
    }
}
