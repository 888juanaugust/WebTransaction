<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Domain\Money;
use App\Domain\Purchasing\PurchaseOrderStatus;
use App\Filament\Actions\PurchaseOrderActions;
use App\Models\PurchaseOrder;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal_po', 'desc')
            ->emptyStateHeading('Belum ada pesanan pembelian')
            ->emptyStateDescription('Buat PO agar penerimaan barang bisa dicocokkan dengan yang dipesan.')
            ->modifyQueryUsing(fn ($query) => $query->withSum('lines as qty_dipesan', 'qty_base')
                ->withSum('lines as qty_diterima', 'qty_base_received'))
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),
                TextColumn::make('supplier.nama')->label('Pemasok')->searchable(),
                TextColumn::make('tanggal_po')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('tanggal_diharapkan')
                    ->label('Diharapkan')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    // Red only when it is genuinely late and still open.
                    ->color(fn (PurchaseOrder $r) => $r->status === PurchaseOrderStatus::Dikirim
                        && $r->tanggal_diharapkan?->isPast()
                            ? 'danger'
                            : 'gray'),

                /*
                 * Progress rather than a raw count. "60 / 100 diterima" is the
                 * one thing somebody scanning this list wants to know, and it
                 * takes two aggregates instead of a query per row.
                 */
                TextColumn::make('progres')
                    ->label('Diterima')
                    ->state(fn (PurchaseOrder $r) => sprintf(
                        '%s / %s',
                        number_format((int) $r->qty_diterima, 0, ',', '.'),
                        number_format((int) $r->qty_dipesan, 0, ',', '.'),
                    ))
                    ->color(fn (PurchaseOrder $r) => (int) $r->qty_diterima >= (int) $r->qty_dipesan
                        ? 'success'
                        : 'warning'),

                TextColumn::make('total_value_rupiah')
                    ->label('Nilai')
                    ->state(fn (PurchaseOrder $r) => $r->status === PurchaseOrderStatus::Draft
                        // A draft has not been totalled yet — sending is what
                        // fixes it. Rp 0 would read as a free order.
                        ? '— belum dikirim —'
                        : Money::format($r->total_value_rupiah)),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderStatus $state) => $state->label())
                    ->color(fn (PurchaseOrderStatus $state) => $state->color()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(PurchaseOrderStatus::cases())
                        ->mapWithKeys(fn (PurchaseOrderStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            /*
             * Grouped behind one menu rather than laid out across the row.
             *
             * Four actions spelled out ran past the right edge, and shortening
             * the labels only moved the clipping around. These are once-per-
             * document actions — a PO is sent once and closed once — so the
             * extra click costs nothing, unlike "Catat pembayaran" on the bills
             * table, which finance uses all day and stays visible there.
             */
            ->recordActions([
                ActionGroup::make([
                    ...PurchaseOrderActions::all(),

                    EditAction::make()
                        ->label('Ubah')
                        ->visible(fn (PurchaseOrder $r) => $r->status->isEditable()),
                ])->tooltip('Tindakan'),
            ]);
    }
}
