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
 * Price list uploads — which are also how the catalogue is loaded.
 *
 * Named "Impor harga" for a year, which cost a tester a morning: they went
 * looking for a separate barang import and concluded products had to be typed
 * in one at a time. They do not. Publishing a version upserts the product
 * master from the same rows (`PriceListImporter::upsertProduct`) — KODE, MERK,
 * KATEGORI, DESCRIPTION, QTY_PER_CTN and SATUAN_DASAR all land on `products`.
 * One file, one screen, both registers. The label now says so.
 *
 * Uploading does not change any price. The file is stored, parsed on the
 * queue, diffed, and only becomes live when somebody publishes it as a new
 * version from this screen.
 */
class PriceListImportResource extends Resource
{
    protected static ?string $model = PriceListImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Impor harga & barang';

    protected static ?string $modelLabel = 'impor harga & barang';

    protected static ?string $pluralModelLabel = 'impor harga & barang';

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

    /**
     * An import is a record of what somebody uploaded and what was decided
     * about it. CLAUDE.md keeps the raw file forever for the same reason, and
     * a record that can be edited or deleted answers whatever the last person
     * to touch it wanted.
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
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
