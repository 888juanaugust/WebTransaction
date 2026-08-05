<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Orders;

use App\Filament\Portal\Concerns\ScopedToBuyer;
use App\Filament\Portal\Resources\Orders\Pages\ListOrders;
use App\Filament\Portal\Resources\Orders\Pages\ViewOrder;
use App\Filament\Portal\Resources\Orders\Schemas\OrderDetail;
use App\Filament\Portal\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The buyer's own order history — item 4 on the portal priority list, and the
 * screen the reorder action lives on, which is item 1.
 */
class OrderResource extends Resource
{
    use ScopedToBuyer;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Pesanan';

    protected static ?string $modelLabel = 'pesanan';

    protected static ?string $pluralModelLabel = 'pesanan';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'pesanan';

    protected static ?string $recordTitleAttribute = 'nomor';

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderDetail::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
