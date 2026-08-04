<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Domain\Orders\OrderStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
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

    protected static ?string $navigationLabel = 'Order';

    protected static ?string $modelLabel = 'order';

    protected static ?string $pluralModelLabel = 'order';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'nomor';

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
