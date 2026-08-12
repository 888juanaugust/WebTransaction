<?php

declare(strict_types=1);

namespace App\Filament\Resources\GoodsReceipts\Tables;

use App\Domain\Money;
use App\Filament\Actions\PostGoodsReceiptAction;
use App\Models\GoodsReceipt;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class GoodsReceiptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Newest first: the thing you just entered is the thing you want.
            ->defaultSort('tanggal_terima', 'desc')
            ->emptyStateHeading('Belum ada penerimaan barang')
            ->emptyStateDescription('Catat penerimaan agar stok bertambah dan harga pokok terbentuk.')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),
                TextColumn::make('supplier.nama')->label('Pemasok')->searchable(),
                TextColumn::make('warehouse.nama')->label('Gudang')->toggleable(),
                TextColumn::make('tanggal_terima')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('nomor_faktur_supplier')
                    ->label('Faktur pemasok')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('lines_count')->label('Baris')->counts('lines'),

                TextColumn::make('total_value_rupiah')
                    ->label('Nilai')
                    ->state(fn (GoodsReceipt $r) => $r->isPosted()
                        ? Money::format($r->total_value_rupiah)
                        // A draft has no total yet — posting is what computes
                        // it. Showing Rp 0 would read as a free delivery.
                        : '— belum diposting —'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        GoodsReceipt::STATUS_DRAFT => 'Draf',
                        GoodsReceipt::STATUS_POSTED => 'Diposting',
                        default => $state,
                    })
                    ->color(fn (string $state) => $state === GoodsReceipt::STATUS_POSTED ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        GoodsReceipt::STATUS_DRAFT => 'Draf',
                        GoodsReceipt::STATUS_POSTED => 'Diposting',
                    ]),
            ])
            ->recordActions([
                PostGoodsReceiptAction::make(),

                // A posted receipt is evidence behind a balance-sheet number.
                EditAction::make()
                    ->label('Ubah')
                    ->visible(fn (GoodsReceipt $r) => ! $r->isPosted()),
            ]);
    }
}
