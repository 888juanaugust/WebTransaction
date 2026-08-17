<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockTransfers\Tables;

use App\Domain\Money;
use App\Filament\Actions\PostStockTransferAction;
use App\Models\StockTransfer;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Transfers, newest first.
 *
 * The value column is there for whoever is allowed to see cost, and absent for
 * everybody else — which on this screen mostly means the warehouse staff who
 * live on it.
 */
class StockTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->weight('medium'),
                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('fromWarehouse.nama')->label('Dari'),
                TextColumn::make('toWarehouse.nama')->label('Ke'),

                TextColumn::make('lines_qty')
                    ->label('Jumlah')
                    ->alignEnd()
                    ->state(fn (StockTransfer $record) => number_format(
                        (int) $record->lines()->sum('qty_base'), 0, ',', '.'
                    )),

                TextColumn::make('total_value_rupiah')
                    ->label('Nilai')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, StockTransfer $record) => $record->isDraft()
                        ? '—'
                        : Money::format((int) $state))
                    // Cost, so warehouse never sees it — the whole point of a
                    // transfer being value-neutral is that they need not care.
                    ->visible(fn () => auth()->user()?->role()->canSeeCost() ?? false),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === StockTransfer::STATUS_POSTED ? 'Diposting' : 'Draf')
                    ->color(fn (string $state) => $state === StockTransfer::STATUS_POSTED ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    StockTransfer::STATUS_DRAFT => 'Draf',
                    StockTransfer::STATUS_POSTED => 'Diposting',
                ]),
            ])
            ->recordActions([
                PostStockTransferAction::make(),
                EditAction::make()->visible(fn (StockTransfer $record) => $record->isDraft()),
            ])
            ->emptyStateHeading('Belum ada transfer gudang')
            ->emptyStateDescription('Transfer memindahkan stok antar gudang tanpa mengubah nilainya.');
    }
}
