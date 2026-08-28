<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Billing\CreditNoteType;
use App\Filament\Actions\PostCreditNoteAction;
use App\Models\CreditNote;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: returs filed by sales, waiting for Inventori to confirm the goods
 * physically came back.
 *
 * A draft sitting here is a customer already promised a credit and boxes
 * already on a truck — posting it is what puts the stock on the shelf and
 * takes the amount off the debt, so the queue lives on the dashboard where
 * Inventori starts the day.
 */
class ReturnsAwaitingVerification extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canVerifyReturns() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Retur menunggu verifikasi')
            ->emptyStateHeading('Tidak ada retur menunggu')
            ->query(
                CreditNote::query()
                    ->where('status', CreditNote::STATUS_DRAFT)
                    ->where('jenis', CreditNoteType::ReturBarang)
                    ->with(['invoice', 'company', 'createdBy', 'warehouse'])
                    ->orderBy('tanggal')
            )
            ->columns([
                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y'),
                TextColumn::make('nomor')->label('Nomor')->searchable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('invoice.nomor')->label('Faktur'),
                TextColumn::make('warehouse.nama')->label('Gudang tujuan'),
                TextColumn::make('alasan')->label('Alasan')->wrap(),
                TextColumn::make('createdBy.name')->label('Diajukan oleh'),
                TextColumn::make('qty')
                    ->label('Unit')
                    ->state(fn (CreditNote $record) => (int) $record->lines()->sum('qty_base')),
            ])
            ->recordActions([
                // The same object the credit note list uses; the label there
                // is "Posting", and here posting *is* the verification.
                PostCreditNoteAction::make(),
            ]);
    }
}
