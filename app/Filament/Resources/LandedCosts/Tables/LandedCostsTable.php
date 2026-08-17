<?php

declare(strict_types=1);

namespace App\Filament\Resources\LandedCosts\Tables;

use App\Domain\Money;
use App\Filament\Actions\PostLandedCostAction;
use App\Filament\Resources\LandedCosts\LandedCostResource;
use App\Models\LandedCost;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Allocations, newest first.
 *
 * The two columns worth looking at are the split: how much of the charge went
 * onto goods still on the shelf, and how much went straight to cost of sales
 * because those goods had already been sold. A run of allocations that are
 * mostly HPP means the freight paperwork is arriving too late to be useful,
 * which is a process problem this screen should make visible.
 */
class LandedCostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (LandedCost $record) => LandedCostResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->weight('medium'),
                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('supplierBillLine.deskripsi')
                    ->label('Biaya')
                    ->placeholder('—')
                    ->description(fn (LandedCost $record) => $record->supplierBillLine
                        ?->supplierBill?->supplier?->nama),

                TextColumn::make('amount_rupiah')
                    ->label('Nilai')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => Money::format((int) $state)),

                /*
                 * The basis is not a column. It matters when the split is
                 * decided and when it is questioned, and both of those happen
                 * on the detail screen — carrying it here cost enough width to
                 * push the status badge and the actions off the right edge.
                 */
                TextColumn::make('ke_persediaan_rupiah')
                    ->label('Persediaan')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, LandedCost $record) => $record->isDraft()
                        ? '—'
                        : Money::format((int) $state)),

                TextColumn::make('ke_hpp_rupiah')
                    ->label('HPP')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, LandedCost $record) => $record->isDraft()
                        ? '—'
                        : Money::format((int) $state))
                    // Not an error, but worth the eye: it is the part of the
                    // charge that arrived too late to sit on any stock.
                    ->color(fn ($state, LandedCost $record) => $record->isPosted() && (int) $state > 0
                        ? 'warning'
                        : null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === LandedCost::STATUS_POSTED ? 'Diposting' : 'Draf')
                    ->color(fn (string $state) => $state === LandedCost::STATUS_POSTED ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    LandedCost::STATUS_DRAFT => 'Draf',
                    LandedCost::STATUS_POSTED => 'Diposting',
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('Lihat rincian'),
                    PostLandedCostAction::make(),
                    DeleteAction::make()->label('Hapus draf'),
                ])->tooltip('Tindakan'),
            ])
            ->emptyStateHeading('Belum ada alokasi biaya perolehan')
            ->emptyStateDescription(
                'Biaya angkut dan bea masuk dicatat sebagai baris berjenis biaya di tagihan pemasok, '
                .'lalu dibebankan ke barangnya dari sini.'
            );
    }
}
