<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Orders\Tables;

use App\Domain\Orders\OrderStatus;
use App\Filament\Portal\Actions\PesanUlangAction;
use App\Filament\Portal\Support\PortalLabels;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
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

                /*
                 * Straight to the delivery note, without opening the order —
                 * the buyer reaching for this is usually matching a delivery
                 * against what arrived, and hides itself until there is a
                 * delivery to show.
                 */
                Action::make('surat_jalan')
                    ->label('Surat jalan')
                    ->icon(Heroicon::OutlinedTruck)
                    ->url(fn (Order $record) => route(
                        'portal.dokumen.surat-jalan',
                        ['order' => $record->id],
                    ))
                    ->openUrlInNewTab()
                    ->visible(fn (Order $record) => in_array(
                        $record->status,
                        [OrderStatus::Shipped, OrderStatus::Completed],
                        true,
                    )),

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
