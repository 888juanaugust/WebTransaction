<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoodsReceipts;

use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\EditGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Schemas\GoodsReceiptForm;
use App\Filament\Resources\GoodsReceipts\Tables\GoodsReceiptsTable;
use App\Models\GoodsReceipt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Penerimaan barang — the document that lets stock arrive.
 *
 * Behind canRecordPurchases(), which is Finance and Owner. Every line carries
 * what we paid, and purchase cost plus selling price is margin.
 *
 * The nav badge counts unposted drafts, because a draft is a delivery that
 * physically happened and has not reached the ledger yet: the stock is on the
 * shelf and the system does not know about it.
 */
class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Penerimaan barang';

    protected static ?string $modelLabel = 'penerimaan barang';

    protected static ?string $pluralModelLabel = 'penerimaan barang';

    protected static \UnitEnum|string|null $navigationGroup = 'Pembelian';

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'penerimaan';

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
     * A posted receipt is never edited.
     *
     * Enforced here as well as in the poster: the poster refuses to post twice,
     * but nothing else would stop somebody opening a posted document and
     * changing a quantity that has already moved the ledger.
     */
    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && ! $record->isPosted();
    }

    /**
     * Never. A draft receipt is emptied, not erased; a posted one moved stock
     * and put a value on it, and the movement outlives the paperwork.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $drafts = GoodsReceipt::query()->draft()->count();

        return $drafts > 0 ? (string) $drafts : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return GoodsReceiptForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsReceiptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceipts::route('/'),
            'create' => CreateGoodsReceipt::route('/create'),
            'edit' => EditGoodsReceipt::route('/{record}/edit'),
        ];
    }
}
