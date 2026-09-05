<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders;

use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Pesanan pembelian — what we asked a supplier for.
 *
 * The nav badge counts orders still out with a supplier, because "what are we
 * still waiting for" is the question this document exists to answer.
 */
class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Pesanan pembelian';

    protected static ?string $modelLabel = 'pesanan pembelian';

    protected static ?string $pluralModelLabel = 'pesanan pembelian';

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PEMBELIAN;

    protected static ?int $navigationSort = 59;

    protected static ?string $slug = 'pesanan-pembelian';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role()->canRecordPurchases() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    /** Sent, closed and cancelled orders are records, not working notes. */
    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && $record->status->isEditable();
    }

    /** Cancelled is a status a PO carries, not a row that goes away. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $open = PurchaseOrder::query()->where('status', PurchaseOrderStatus::Dikirim->value)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
