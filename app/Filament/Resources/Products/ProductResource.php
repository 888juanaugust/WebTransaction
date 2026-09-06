<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products;

use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The catalogue. Prices do not live here — they live in price list versions,
 * and are only ever changed by publishing a new one.
 *
 * Reading is broad and writing is not, and until this was measured neither
 * was anything: the class declared no access methods, so Filament's
 * permissive default answered for it and **every role could create, edit and
 * delete a SKU**. Measured as a Gudang clerk — the one role CLAUDE.md says
 * outright cannot touch the catalogue — `qty_per_ctn` went from 18 to 1, and
 * then the product was deleted outright, leaving its stock ledger behind.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::INVENTORI;

    protected static ?string $navigationLabel = 'Katalog';

    protected static ?string $modelLabel = 'produk';

    protected static ?string $pluralModelLabel = 'produk';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'kode';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canBrowseCatalogue() ?? false;
    }

    /**
     * Never from here. Items arrive through Impor barang, in bulk.
     *
     * The one-at-a-time form is gone on purpose. A SKU's `satuan_dasar` and
     * `qty_per_ctn` are the arithmetic every order and every stock movement
     * runs through, and one validated door for them beats two that drift —
     * the importer checks the brand, the category, the base unit, duplicate
     * codes within the file, and refuses to re-denominate a SKU that already
     * has stock. A form checks whatever its fields happen to say.
     *
     * The cost is real and small: adding a single item means a one-row CSV.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->role()->canManageCatalogue() ?? false;
    }

    /**
     * Only the catalogue-keeper, and only for a SKU nothing has happened to.
     *
     * The second half is not an authorisation question but a bookkeeping one,
     * and it is answered again in `Product::deleting` so that a console
     * one-liner meets the same refusal. Here it decides whether the button is
     * offered at all: a Delete that always errors is worse than no Delete.
     */
    public static function canDelete(Model $record): bool
    {
        return (auth()->user()?->role()->canManageCatalogue() ?? false)
            && $record instanceof Product
            && ! $record->hasHistory();
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
