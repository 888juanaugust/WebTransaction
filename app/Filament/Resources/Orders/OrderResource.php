<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Support\Icons\Heroicon;

/**
 * Orders are worked from the dashboard queues. This resource is the
 * behind-the-queue view: history, lookup, and corrections.
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

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    /** Orders are created by the sales flow, not by hand in a CRUD form. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
