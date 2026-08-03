<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Domain\Money;
use App\Domain\Payments\PaymentLedger;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('order.nomor')->label('Order')->searchable(),
                TextColumn::make('issued_on')->label('Tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('due_date')->label('Jatuh tempo')->date('d/m/Y')->sortable(),

                TextColumn::make('dpp_rupiah')
                    ->label('DPP')
                    ->formatStateUsing(fn (int $state) => Money::format($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ppn_rupiah')
                    ->label('PPN')
                    ->formatStateUsing(fn (int $state) => Money::format($state))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_rupiah')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state) => Money::format($state))
                    ->sortable(),

                TextColumn::make('sisa')
                    ->label('Sisa')
                    ->state(fn (Invoice $record) => Money::format($record->amountOutstanding())),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Invoice::STATUS_OPEN => 'Terbuka',
                        Invoice::STATUS_PAID => 'Lunas',
                        Invoice::STATUS_VOID => 'Batal',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        Invoice::STATUS_PAID => 'success',
                        Invoice::STATUS_VOID => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('nsfp')->label('NSFP')->placeholder('— belum ada —')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        Invoice::STATUS_OPEN => 'Terbuka',
                        Invoice::STATUS_PAID => 'Lunas',
                        Invoice::STATUS_VOID => 'Batal',
                    ]),

                Filter::make('jatuh_tempo')
                    ->label('Jatuh tempo')
                    ->query(fn ($query) => $query->overdue()),
            ])
            ->recordActions([
                /*
                 * Manual payment entry, for transfers that did not come
                 * through the gateway. Restricted to Finance and Owner, and it
                 * appends to the ledger — it never edits the invoice amount.
                 */
                Action::make('catat_pembayaran')
                    ->label('Catat pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (Invoice $record) => $record->status === Invoice::STATUS_OPEN
                        && auth()->user()->role()->canConfirmPayment())
                    ->schema(fn (Invoice $record) => [
                        TextInput::make('amount_rupiah')
                            ->label('Jumlah (Rp)')
                            ->numeric()
                            ->required()
                            ->default($record->amountOutstanding()),
                        DateTimePicker::make('paid_at')
                            ->label('Tanggal terima')
                            ->default(now()),
                        Textarea::make('catatan')->label('Catatan'),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        app(PaymentLedger::class)->recordManualPayment(
                            company: $record->company,
                            amountRupiah: (int) $data['amount_rupiah'],
                            actor: auth()->user(),
                            invoice: $record,
                            catatan: $data['catatan'] ?? null,
                            paidAt: $data['paid_at'] ? Carbon::parse($data['paid_at']) : null,
                        );

                        Notification::make()->title('Pembayaran dicatat')->success()->send();
                    }),
            ])
            ->defaultSort('issued_on', 'desc');
    }
}
