<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Money;
use App\Domain\Payments\PaymentLedger;
use App\Models\Invoice;
use App\Models\PaymentEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: money received that has not been matched to an invoice.
 *
 * With a fixed VA the transfer carries no order number, so some payments will
 * always land here. They are recorded on the ledger either way — allocation is
 * bookkeeping, not a change to the money.
 */
class UnmatchedPayments extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canConfirmPayment() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Pembayaran belum dicocokkan')
            ->emptyStateHeading('Semua pembayaran sudah dicocokkan')
            ->query(
                PaymentEntry::query()
                    ->unmatched()
                    ->with('company')
                    ->orderByDesc('paid_at')
            )
            ->columns([
                TextColumn::make('paid_at')->label('Tanggal')->dateTime('d/m/Y H:i'),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('amount_rupiah')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('gateway')->label('Sumber')->placeholder('manual'),
                TextColumn::make('gateway_reference')->label('Referensi')->limit(24),
            ])
            ->recordActions([
                Action::make('cocokkan')
                    ->label('Cocokkan ke faktur')
                    ->icon('heroicon-o-link')
                    ->schema(fn (PaymentEntry $record) => [
                        Select::make('invoice_id')
                            ->label('Faktur')
                            ->options(
                                Invoice::query()
                                    ->where('company_id', $record->company_id)
                                    ->where('status', Invoice::STATUS_OPEN)
                                    ->orderBy('due_date')
                                    ->get()
                                    ->mapWithKeys(fn (Invoice $invoice) => [
                                        $invoice->id => $invoice->nomor.' — '
                                            .Money::format($invoice->amountOutstanding()).' sisa',
                                    ])
                            )
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (PaymentEntry $record, array $data) {
                        app(PaymentLedger::class)->allocateToInvoice(
                            $record,
                            Invoice::findOrFail($data['invoice_id']),
                            auth()->user(),
                        );

                        Notification::make()->title('Pembayaran dicocokkan')->success()->send();
                    }),
            ]);
    }
}
