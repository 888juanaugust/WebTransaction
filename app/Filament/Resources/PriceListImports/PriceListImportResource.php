<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceListImports;

use App\Filament\Resources\PriceListImports\Pages\CreatePriceListImport;
use App\Filament\Resources\PriceListImports\Pages\ListPriceListImports;
use App\Filament\Resources\PriceListImports\Schemas\PriceListImportForm;
use App\Filament\Resources\PriceListImports\Tables\PriceListImportsTable;
use App\Models\PriceListImport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Price list uploads.
 *
 * Uploading does not change any price. The file is stored, parsed on the
 * queue, diffed, and only becomes live when somebody publishes it as a new
 * version from this screen.
 */
class PriceListImportResource extends Resource
{
    protected static ?string $model = PriceListImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Impor harga';

    protected static ?string $modelLabel = 'impor harga';

    protected static ?string $pluralModelLabel = 'impor harga';

    protected static ?int $navigationSort = 50;

    /**
     * The price list is Inventori's under the new organisation — the
     * catalogue-keeper maintains what things cost to buy, and the one screen
     * that changes selling prices moved with the catalogue. Sales sell from
     * the list; they no longer publish it.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canManagePriceList() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->role()->canManagePriceList() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return PriceListImportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PriceListImportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceListImports::route('/'),
            'create' => CreatePriceListImport::route('/create'),
        ];
    }
}
