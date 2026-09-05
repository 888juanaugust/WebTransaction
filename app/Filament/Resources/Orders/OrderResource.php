<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Domain\Orders\OrderStatus;
use App\Filament\Navigation\SidebarGroups;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderDetail;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Orders are worked from the dashboard queues; this resource is where they are
 * entered, looked up, and corrected.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::PENJUALAN;

    protected static ?string $navigationLabel = 'Order';

    protected static ?string $modelLabel = 'order';

    protected static ?string $pluralModelLabel = 'order';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'nomor';

    /**
     * Everybody working an order can read it.
     *
     * Spelled out rather than left to Filament's default, which is what it
     * was relying on. The default happened to be right here — sales place
     * orders, marketing approve them, finance bills them, the warehouses pick
     * them — but "right by accident" and "right on purpose" read identically
     * until somebody changes the default, and only one of them survives it.
     */
    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    /**
     * Never from here.
     *
     * An order is erased through OrderTransitionActions, which goes via
     * OrderEraser: draft or submitted only, by the seat that owns the
     * customer's queue, with an audit snapshot written before the row goes.
     * This said yes to all six roles — no Delete button was rendered, so
     * nothing exploited it, but a resource that answers "yes, anybody" to a
     * question it never intends to be asked is one action away from meaning
     * it.
     */
    public static function canDelete($record): bool
    {
        return false;
    }

    /** Sales and Owner take orders; warehouse and finance do not. */
    public static function canCreate(): bool
    {
        return auth()->user()?->role()->canCreateOrders() ?? false;
    }

    /**
     * Only a draft is editable.
     *
     * From `confirmed` onward the lines carry price snapshots and hold a stock
     * reservation. Editing those outside the state machine is exactly what the
     * invariants exist to prevent, so the form is closed rather than trusted.
     */
    public static function canEdit($record): bool
    {
        return $record->status === OrderStatus::Draft
            && (auth()->user()?->role()->canCreateOrders() ?? false);
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    /**
     * The view page reads snapshots; the form only ever writes drafts.
     *
     * Without this, Filament renders the *entry form* disabled — which looked
     * like a detail page but resolved prices live, so a historical order
     * showed today's figures, to seats that may not see a price at all.
     */
    public static function infolist(Schema $schema): Schema
    {
        return OrderDetail::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
