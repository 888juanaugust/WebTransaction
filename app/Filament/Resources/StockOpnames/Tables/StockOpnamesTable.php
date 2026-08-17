<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockOpnames\Tables;

use App\Domain\Money;
use App\Filament\Actions\PostStockOpnameAction;
use App\Models\StockOpname;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Count sheets, newest first.
 *
 * Two variance columns, and only one of them is for everybody. The quantity is
 * warehouse business — it is what they counted. The rupiah is cost, so it is
 * shown to the roles that may see cost and nobody else.
 */
class StockOpnamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->weight('medium'),
                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('warehouse.nama')->label('Gudang'),

                TextColumn::make('counted_lines')
                    ->label('Dihitung')
                    ->alignEnd()
                    ->state(fn (StockOpname $record) => sprintf(
                        '%d / %d',
                        $record->lines()->whereNotNull('qty_counted')->count(),
                        $record->lines()->count(),
                    )),

                TextColumn::make('selisih_qty')
                    ->label('Selisih')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, StockOpname $record) => $record->isDraft()
                        ? '—'
                        : number_format((int) $state, 0, ',', '.'))
                    ->color(fn ($state, StockOpname $record) => $record->isPosted() && (int) $state !== 0
                        ? 'danger'
                        : null),

                TextColumn::make('selisih_rupiah')
                    ->label('Nilai selisih')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, StockOpname $record) => $record->isDraft()
                        ? '—'
                        : Money::format((int) $state))
                    ->visible(fn () => auth()->user()?->role()->canSeeCost() ?? false),

                TextColumn::make('countedBy.name')->label('Dihitung oleh')->placeholder('—'),
                TextColumn::make('postedBy.name')->label('Disetujui oleh')->placeholder('—'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === StockOpname::STATUS_POSTED ? 'Disetujui' : 'Draf')
                    ->color(fn (string $state) => $state === StockOpname::STATUS_POSTED ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    StockOpname::STATUS_DRAFT => 'Draf',
                    StockOpname::STATUS_POSTED => 'Disetujui',
                ]),
            ])
            /*
             * Grouped behind one button rather than laid out inline. Nine
             * columns plus two long labels pushed "Setujui selisih" past the
             * right edge of the table for Finance, which is precisely the role
             * that needs to click it.
             */
            ->recordActions([
                ActionGroup::make([
                    PostStockOpnameAction::make(),
                    EditAction::make()
                        ->label('Isi hitungan')
                        ->visible(fn (StockOpname $record) => $record->isDraft()
                            && (auth()->user()?->role()->canCountStock() ?? false)),
                ])->tooltip('Tindakan'),
            ])
            ->emptyStateHeading('Belum ada stok opname')
            ->emptyStateDescription('Lembar opname dibuat per gudang, lalu diisi di rak.');
    }
}
