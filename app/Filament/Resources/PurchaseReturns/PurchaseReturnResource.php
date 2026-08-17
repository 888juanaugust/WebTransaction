<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseReturns;

use App\Filament\Resources\PurchaseReturns\Pages\EditPurchaseReturn;
use App\Filament\Resources\PurchaseReturns\Pages\ListPurchaseReturns;
use App\Filament\Resources\PurchaseReturns\Pages\ViewPurchaseReturn;
use App\Filament\Resources\PurchaseReturns\Schemas\PurchaseReturnDetail;
use App\Filament\Resources\PurchaseReturns\Schemas\PurchaseReturnForm;
use App\Filament\Resources\PurchaseReturns\Tables\PurchaseReturnsTable;
use App\Models\PurchaseReturn;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Retur pembelian — goods going back to a supplier.
 *
 * No create page. A return is drawn off a posted goods receipt by
 * PurchaseReturnIssuer, because every line has to point at something that
 * actually arrived and carry the cost it arrived at. A blank form would let
 * somebody return a SKU this supplier never delivered, at a price nobody
 * agreed, and the document would have nothing to check itself against.
 *
 * Editing a draft is only ever about quantity: how much of each line is going
 * back, and which lines are not going at all. There is no money on the form.
 */
class PurchaseReturnResource extends Resource
{
    protected static ?string $model = PurchaseReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?string $navigationLabel = 'Retur pembelian';

    protected static ?string $modelLabel = 'retur pembelian';

    protected static ?string $pluralModelLabel = 'retur pembelian';

    protected static \UnitEnum|string|null $navigationGroup = 'Pembelian';

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'retur-pembelian';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canReturnToSupplier() ?? false;
    }

    /** Drawn from a receipt, not typed. See the list page's action. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Only a draft, and only the quantities.
     *
     * A posted return is evidence behind a stock movement and a payable, like
     * every other posted document here. A mistake is corrected by receiving
     * the goods back in, not by editing this.
     */
    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && ! $record->isPosted();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && ! $record->isPosted();
    }

    /**
     * Returns the supplier has not acknowledged yet.
     *
     * The count is posted returns with no `nomor_nota_kredit_supplier` against
     * them — goods that have physically gone back and that nobody has yet sent
     * us a credit note for. That gap is money, and nothing else in the system
     * watches it: the payable is already reduced in our books, so if the
     * supplier never agrees, the disagreement surfaces at the next payment run
     * rather than here.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $waiting = PurchaseReturn::query()
            ->posted()
            ->whereNull('nomor_nota_kredit_supplier')
            ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseReturnForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseReturnsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseReturnDetail::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseReturns::route('/'),
            'view' => ViewPurchaseReturn::route('/{record}'),
            'edit' => EditPurchaseReturn::route('/{record}/edit'),
        ];
    }
}
