<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Money;
use App\Domain\Payments\PaymentLedger;
use App\Models\Invoice;
use App\Models\PaymentEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Throwable;

/**
 * Queue: money received with some of it still applied to nothing.
 *
 * A bank transfer carries no faktur number, so some payments will always land
 * here — and so will one that was matched to a bill smaller than itself, since
 * what matters is not whether somebody named an invoice but whether all of the
 * money has been accounted for. They are recorded on the ledger either way:
 * allocation is bookkeeping about the money, not a change to it.
 *
 * The summary lives here; the work is on Terima pembayaran, where a single
 * receipt can be spread across several fakturs at the moment it is banked.
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
                // What is actually outstanding on this receipt. Equal to the
                // amount for an untouched one, less for a partly applied one
                // — and the second kind belongs here just as much.
                TextColumn::make('sisa')
                    ->label('Belum dipakai')
                    ->state(fn (PaymentEntry $record) => Money::format(
                        app(PaymentLedger::class)->unallocated($record)
                    )),

                TextColumn::make('catatan')->label('Catatan')->limit(40)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('cocokkan')
                    ->label('Cocokkan ke faktur')
                    ->icon('heroicon-o-link')
                    ->schema(fn (PaymentEntry $record) => [
                        Select::make('invoice_id')
                            ->label('Faktur')
                            ->options(
                                app(PaymentLedger::class)
                                    ->openInvoices($record->company)
                                    ->mapWithKeys(fn (Invoice $invoice) => [
                                        $invoice->id => $invoice->nomor.' — '
                                            .Money::format($invoice->amountOutstanding()).' sisa',
                                    ])
                            )
                            ->required()
                            ->searchable()
                            ->live()
                            // Set on change, not as a default: a default is
                            // evaluated at mount, before any faktur is
                            // chosen, and would leave the box empty.
                            ->afterStateUpdated(fn ($state, $set) => $set(
                                'jumlah',
                                static::usulan($record, (int) $state),
                            )),

                        /*
                         * An amount, because a transfer rarely matches one
                         * faktur exactly. Defaulted to whichever runs out
                         * first — the money or the bill — so the ordinary
                         * case is still one click, and the remainder stays
                         * in this queue instead of vanishing into a
                         * negative balance.
                         */
                        TextInput::make('jumlah')
                            ->label('Dipakai (Rp)')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->helperText('Sisa penerimaan ini: '
                                .Money::format(app(PaymentLedger::class)->unallocated($record))),
                    ])
                    ->action(function (PaymentEntry $record, array $data) {
                        try {
                            app(PaymentLedger::class)->allocate(
                                entry: $record,
                                invoice: Invoice::query()->withoutGlobalScope('region')
                                    ->findOrFail((int) $data['invoice_id']),
                                amountRupiah: (int) $data['jumlah'],
                                actor: auth()->user(),
                            );

                            Notification::make()->title('Pembayaran dicocokkan')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Tidak bisa dicocokkan')
                                ->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    /** Whichever runs out first: what is left of the money, or of the bill. */
    private static function usulan(PaymentEntry $entry, int $invoiceId): ?int
    {
        $invoice = $invoiceId > 0
            ? Invoice::query()->withoutGlobalScope('region')->find($invoiceId)
            : null;

        if ($invoice === null) {
            return null;
        }

        return max(0, min(
            app(PaymentLedger::class)->unallocated($entry),
            $invoice->amountOutstanding(),
        ));
    }
}
