<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseReturns\Tables;

use App\Domain\Money;
use App\Filament\Actions\PostPurchaseReturnAction;
use App\Filament\Resources\PurchaseReturns\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Returns, newest first.
 *
 * The column that earns its place is the supplier's credit note, because an
 * empty one is a work item: the goods have gone, our books already say the
 * supplier owes us, and until they acknowledge it that is our word against
 * theirs. Everything else on the row is context for it.
 *
 * The split between debt and accrual is not a column. It is what the document
 * is about, but it needs two figures and a sentence to make sense of, and both
 * live on the detail screen — carrying them here made the table wider than the
 * window on the laptops this is used on.
 */
class PurchaseReturnsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (PurchaseReturn $record) => PurchaseReturnResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->weight('medium'),
                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('supplier.nama')
                    ->label('Pemasok')
                    ->searchable()
                    ->description(fn (PurchaseReturn $record) => $record->goodsReceipt?->nomor),

                TextColumn::make('total_rupiah')
                    ->label('Kredit pemasok')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state, PurchaseReturn $record) => $record->isPosted()
                        ? Money::format((int) $state)
                        : '—'),

                /*
                 * No warning colour on the empty case: a colour closure only
                 * runs against a state, and an empty column renders the
                 * placeholder's own styling instead — the amber never
                 * appeared. What actually surfaces an unacknowledged return is
                 * the sidebar badge and the filter beneath this table, both of
                 * which count the same thing.
                 */
                TextColumn::make('nomor_nota_kredit_supplier')
                    ->label('Nota kredit pemasok')
                    ->placeholder('Belum diterima'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === PurchaseReturn::STATUS_POSTED
                        ? 'Diposting'
                        : 'Draf')
                    ->color(fn (string $state) => $state === PurchaseReturn::STATUS_POSTED ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    PurchaseReturn::STATUS_DRAFT => 'Draf',
                    PurchaseReturn::STATUS_POSTED => 'Diposting',
                ]),

                Filter::make('belum_diakui')
                    ->label('Belum diakui pemasok')
                    ->query(fn (Builder $query) => $query
                        ->where('status', PurchaseReturn::STATUS_POSTED)
                        ->whereNull('nomor_nota_kredit_supplier')),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('Lihat rincian'),
                    EditAction::make()->label('Ubah draf'),
                    PostPurchaseReturnAction::make(),
                    Action::make('cetak')
                        ->label('Cetak nota retur')
                        ->icon(Heroicon::OutlinedPrinter)
                        ->url(fn (PurchaseReturn $record) => route('dokumen.retur-pembelian', $record))
                        ->openUrlInNewTab()
                        ->visible(fn (PurchaseReturn $record) => $record->isPosted()),
                    DeleteAction::make()->label('Hapus draf'),
                ])->tooltip('Tindakan'),
            ])
            ->emptyStateHeading('Belum ada retur pembelian')
            ->emptyStateDescription(
                'Retur dibuat dari penerimaan barang yang sudah diposting — pilih penerimaannya, '
                .'lalu kurangi atau hapus baris yang tidak jadi dikembalikan.'
            );
    }
}
