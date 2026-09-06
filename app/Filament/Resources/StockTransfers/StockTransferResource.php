<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockTransfers;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\EditStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\Schemas\StockTransferForm;
use App\Filament\Resources\StockTransfers\Tables\StockTransfersTable;
use App\Models\StockTransfer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Transfer gudang — goods moving between our own warehouses.
 *
 * Warehouse work. No money control is needed because a transfer cannot change
 * what the inventory is worth: `product_costs` is keyed by SKU, so the value
 * that leaves one shelf arrives on the other unchanged.
 */
class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Transfer gudang';

    protected static ?string $modelLabel = 'transfer gudang';

    protected static ?string $pluralModelLabel = 'transfer gudang';

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::INVENTORI;

    protected static ?int $navigationSort = 26;

    protected static ?string $slug = 'transfer-gudang';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canTransferStock() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    /** A posted transfer is never edited. The goods have already moved. */
    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->isDraft();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    public static function form(Schema $schema): Schema
    {
        return StockTransferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockTransfersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'edit' => EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
