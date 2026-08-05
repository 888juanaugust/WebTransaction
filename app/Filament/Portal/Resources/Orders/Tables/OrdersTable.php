<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Orders\Tables;

use App\Domain\Orders\OrderStatus;
use App\Filament\Portal\Actions\PesanUlangAction;
use App\Filament\Portal\Support\PortalLabels;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada pesanan')
            ->emptyStateDescription('Pesanan Anda akan muncul di sini.')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),

                TextColumn::make('created_at')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => self::statusColor($state)),

                TextColumn::make('lines_count')->label('Baris')->counts('lines'),

                TextColumn::make('po_pelanggan')
                    ->label('PO Anda')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('total_rupiah')
                    ->label('Total')
                    ->state(fn (Order $record) => PortalLabels::orderTotal($record)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
                PesanUlangAction::make(),
            ])
            ->paginated([10, 25, 50]);
    }

    /** Green is reserved for settled money; red means something went wrong. */
    public static function statusColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Completed, OrderStatus::Paid => 'success',
            OrderStatus::Rejected, OrderStatus::Expired => 'danger',
            OrderStatus::Draft => 'gray',
            default => 'warning',
        };
    }
}
