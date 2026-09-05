<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Schemas\SupplierForm;
use App\Filament\Resources\Suppliers\Tables\SuppliersTable;
use App\Models\Supplier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Who we buy from.
 *
 * Behind the purchasing permission rather than the credit one: a supplier
 * record exists to be attached to a goods receipt, and a goods receipt carries
 * what we paid.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Pemasok';

    protected static ?string $modelLabel = 'pemasok';

    protected static ?string $pluralModelLabel = 'pemasok';

    protected static \UnitEnum|string|null $navigationGroup = 'Pembelian';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'pemasok';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canRecordPurchases() ?? false;
    }

    /** What the inherited default was already doing, now on purpose. */
    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    /**
     * Never from here. A supplier is referenced by every bill, receipt and
     * purchase order ever raised against them; the answer to "we stopped
     * buying from them" is the aktif flag, not a missing row.
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
