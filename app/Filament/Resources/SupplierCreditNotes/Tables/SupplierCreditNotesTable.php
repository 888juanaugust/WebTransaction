<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierCreditNotes\Tables;

use App\Domain\Money;
use App\Domain\Purchasing\SupplierCreditNoteIssuer;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class SupplierCreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal', 'desc')
            ->columns([
                TextColumn::make('nomor')
                    ->label('Nomor')
                    ->searchable()
                    ->description(fn (SupplierCreditNote $r) => $r->nomor_nota_supplier
                        ? 'nota pemasok '.$r->nomor_nota_supplier
                        : null),

                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),

                /*
                 * The bill rides under the supplier rather than taking a column
                 * of its own. Seven columns push Nilai and Status off the right
                 * edge, and those are what people scan the list for.
                 *
                 * A blanket rebate belongs to no single bill, and saying so is
                 * better than a blank somebody reads as missing.
                 */
                TextColumn::make('supplier.nama')
                    ->label('Pemasok')
                    ->description(fn (SupplierCreditNote $r) => $r->bill
                        ? 'atas '.$r->bill->nomor
                        : 'tanpa tagihan tertentu')
                    ->searchable(),

                // Limited rather than wrapped: a wrapped reason grows the row
                // to six lines and the table past the viewport with it.
                TextColumn::make('alasan')
                    ->label('Alasan')
                    ->limit(24)
                    ->tooltip(fn (SupplierCreditNote $r) => strlen((string) $r->alasan) > 24 ? $r->alasan : null)
                    ->searchable(),

                TextColumn::make('account.nama')
                    ->label('Lawan jurnal')
                    ->description(fn (SupplierCreditNote $r) => $r->account?->kode)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_rupiah')
                    ->label('Nilai')
                    ->state(fn (SupplierCreditNote $r) => Money::format((int) $r->total_rupiah))
                    ->description(fn (SupplierCreditNote $r) => $r->ppn_rupiah > 0
                        ? 'termasuk PPN '.Money::format((int) $r->ppn_rupiah)
                        : null)
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (SupplierCreditNote $r) => $r->isPosted() ? 'Diposting' : 'Draf')
                    ->color(fn (SupplierCreditNote $r) => $r->isPosted() ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    SupplierCreditNote::STATUS_DRAFT => 'Draf',
                    SupplierCreditNote::STATUS_POSTED => 'Diposting',
                ]),

                SelectFilter::make('supplier_id')
                    ->label('Pemasok')
                    ->options(fn () => Supplier::query()->orderBy('nama')->pluck('nama', 'id')->all())
                    ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([self::posting(), self::buang()])->tooltip('Tindakan'),
            ])
            ->emptyStateHeading('Belum ada nota kredit pemasok')
            ->emptyStateDescription(
                'Untuk harga yang dikoreksi pemasok tanpa barang kembali. Kalau barangnya '
                .'memang dikirim balik, pakai retur pembelian — itu yang menurunkan stok.'
            );
    }

    private static function posting(): Action
    {
        return Action::make('posting')
            ->label('Posting')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->requiresConfirmation()
            ->modalHeading('Posting nota kredit')
            ->modalDescription(
                'Utang ke pemasok turun sebesar nilai ini. Stok tidak bergerak sama sekali.'
            )
            ->visible(fn (SupplierCreditNote $record) => $record->isDraft())
            ->action(function (SupplierCreditNote $record) {
                try {
                    $note = app(SupplierCreditNoteIssuer::class)->post($record, auth()->user());

                    Notification::make()
                        ->title("Nota kredit {$note->nomor} diposting")
                        ->body('Utang usaha sudah turun '.Money::format((int) $note->total_rupiah).'.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa diposting')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private static function buang(): Action
    {
        return Action::make('buang')
            ->label('Buang draf')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Draf ini belum memposting apa pun, jadi tidak ada yang perlu dibalik.')
            ->visible(fn (SupplierCreditNote $record) => $record->isDraft())
            ->action(function (SupplierCreditNote $record) {
                try {
                    $nomor = $record->nomor;
                    app(SupplierCreditNoteIssuer::class)->discard($record, auth()->user());

                    Notification::make()->title("Draf {$nomor} dibuang")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dibuang')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
