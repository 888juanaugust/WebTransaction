<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockOpnames;

use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\Schemas\StockOpnameForm;
use App\Filament\Resources\StockOpnames\Tables\StockOpnamesTable;
use App\Models\StockOpname;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Stok opname — counting the shelf, and signing off what it found.
 *
 * Read by whoever counts *or* approves, which is a wider set than either
 * permission alone: warehouse fill the sheet in, finance approve the variance,
 * and each needs to see the other's work.
 *
 * There is no create page. A sheet has to cover every SKU the warehouse holds,
 * including the ones the system says are at zero, so it is drawn by
 * StockOpnameSheet from the shelf rather than typed line by line. The list
 * page's action is what draws it.
 */
class StockOpnameResource extends Resource
{
    protected static ?string $model = StockOpname::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Stok opname';

    protected static ?string $modelLabel = 'stok opname';

    protected static ?string $pluralModelLabel = 'stok opname';

    protected static \UnitEnum|string|null $navigationGroup = 'Gudang';

    protected static ?int $navigationSort = 27;

    protected static ?string $slug = 'stok-opname';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        $role = auth()->user()?->role();

        return $role !== null && ($role->canCountStock() || $role->canApproveStockCount());
    }

    /** Sheets are drawn from the shelf, not typed. See the list page. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Filling in the count is the counter's job, and only while it is open. */
    public static function canEdit(Model $record): bool
    {
        return (auth()->user()?->role()->canCountStock() ?? false) && $record->isDraft();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }

    /**
     * A warehouse with an unapproved count is a warehouse whose figures are
     * in question. Worth a badge.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $open = StockOpname::query()->where('status', StockOpname::STATUS_DRAFT)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function form(Schema $schema): Schema
    {
        return StockOpnameForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockOpnamesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnames::route('/'),
            'edit' => EditStockOpname::route('/{record}/edit'),
        ];
    }
}
