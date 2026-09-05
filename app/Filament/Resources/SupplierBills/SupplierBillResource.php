<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierBills;

use App\Filament\Resources\SupplierBills\Pages\CreateSupplierBill;
use App\Filament\Resources\SupplierBills\Pages\EditSupplierBill;
use App\Filament\Resources\SupplierBills\Pages\ListSupplierBills;
use App\Filament\Resources\SupplierBills\Schemas\SupplierBillForm;
use App\Filament\Resources\SupplierBills\Tables\SupplierBillsTable;
use App\Models\SupplierBill;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tagihan pemasok — what we owe, and the PPN masukan we can credit.
 *
 * The nav badge counts bills already past due, which is the only number on this
 * screen that costs money to ignore.
 */
class SupplierBillResource extends Resource
{
    protected static ?string $model = SupplierBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Tagihan pemasok';

    protected static ?string $modelLabel = 'tagihan pemasok';

    protected static ?string $pluralModelLabel = 'tagihan pemasok';

    protected static \UnitEnum|string|null $navigationGroup = 'Pembelian';

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'tagihan-pemasok';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canRecordPurchases() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    /**
     * A posted bill is never edited — by anybody, including the owner.
     *
     * The mirror of the rule on customer invoices: whoever pays must not be
     * able to move the amount owed.
     */
    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->posted_at === null;
    }

    /** A posted bill is money owed; it is credited, never removed. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $overdue = SupplierBill::query()->overdue()->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return SupplierBillForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupplierBillsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierBills::route('/'),
            'create' => CreateSupplierBill::route('/create'),
            'edit' => EditSupplierBill::route('/{record}/edit'),
        ];
    }
}
