<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Widgets;

use App\Client\Portal\Filament\Resources\Orders\OrderResource;
use App\Client\Portal\Portal;
use App\Filament\Support\Columns\Rupiah;
use App\Filament\Support\Columns\Tanggal;
use App\Models\Sales\SalesOrder;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** The last orders, newest first, with where each stands. */
class LastOrders extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Last orders'))
            ->query(fn () => SalesOrder::query()->where('customer_id', Portal::customer()->id)->orderByDesc('trans_date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('number')->label(__('Order No.'))->fontFamily('mono'),
                Tanggal::make('trans_date')->label(__('fields.trans_date')),
                TextColumn::make('approval_status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (SalesOrder $r) => OrderResource::statusLabel($r))
                    ->color(fn (SalesOrder $r) => OrderResource::statusColor($r)),
                Rupiah::make('total')->label(__('fields.total')),
            ])
            ->recordActions([
                Action::make('open')->label(__('Open'))->url(fn (SalesOrder $r) => OrderResource::getUrl('view', ['record' => $r])),
            ])
            ->paginated([5, 10])
            ->emptyStateHeading(__('No orders yet'));
    }
}
