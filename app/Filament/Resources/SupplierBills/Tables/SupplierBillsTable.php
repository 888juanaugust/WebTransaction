<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierBills\Tables;

use App\Domain\Banking\BankAccounts;
use App\Domain\Money;
use App\Domain\Purchasing\SupplierBillPoster;
use App\Domain\Purchasing\SupplierLedger;
use App\Models\BankAccount;
use App\Models\SupplierBill;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class SupplierBillsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Soonest due first: an AP list sorted by number is a list nobody
            // can act on. Same reasoning as the customer-side AR table.
            ->defaultSort('due_date')
            ->emptyStateHeading('Belum ada tagihan pemasok')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),
                TextColumn::make('supplier.nama')->label('Pemasok')->searchable(),

                /*
                 * Hidden by default, still searchable. It is how finance finds
                 * a bill when the supplier phones, not something you scan the
                 * list by — and the width it took pushed the row actions off
                 * the right edge on a 1500px screen.
                 */
                TextColumn::make('nomor_faktur_supplier')
                    ->label('Faktur pemasok')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('due_date')
                    ->label('Jatuh tempo')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn (SupplierBill $r) => $r->status === SupplierBill::STATUS_OPEN
                        && $r->due_date->isPast() ? 'danger' : 'gray'),

                TextColumn::make('total_rupiah')
                    ->label('Nilai')
                    ->state(fn (SupplierBill $r) => $r->posted_at === null
                        ? '— belum diposting —'
                        : Money::format($r->total_rupiah)),

                TextColumn::make('ppn_rupiah')
                    ->label('PPN masukan')
                    ->state(fn (SupplierBill $r) => Money::format($r->ppn_rupiah))
                    // Grey when it cannot actually be credited: PPN without the
                    // supplier's faktur pajak behind it is not money back.
                    ->color(fn (SupplierBill $r) => $r->isCreditableInput() ? 'success' : 'gray')
                    ->tooltip(fn (SupplierBill $r) => $r->isCreditableInput()
                        ? 'Bisa dikreditkan'
                        : 'Belum ada nomor faktur pajak — belum bisa dikreditkan')
                    ->toggleable(),

                TextColumn::make('sisa')
                    ->label('Sisa')
                    ->weight('bold')
                    ->state(fn (SupplierBill $r) => Money::format($r->amountOutstanding())),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        SupplierBill::STATUS_OPEN => 'Terbuka',
                        SupplierBill::STATUS_PAID => 'Lunas',
                        SupplierBill::STATUS_VOID => 'Batal',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        SupplierBill::STATUS_PAID => 'success',
                        SupplierBill::STATUS_VOID => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        SupplierBill::STATUS_OPEN => 'Terbuka',
                        SupplierBill::STATUS_PAID => 'Lunas',
                        SupplierBill::STATUS_VOID => 'Batal',
                    ]),

                Filter::make('jatuh_tempo')->label('Sudah jatuh tempo')
                    ->query(fn ($query) => $query->overdue()),
            ])
            ->recordActions([
                Action::make('posting')
                    ->label('Posting')
                    // Icon only: spelled out, it crowded "Catat pembayaran" —
                    // the action this page exists for — off the screen.
                    ->iconButton()
                    ->tooltip('Posting tagihan')
                    ->icon('heroicon-o-check-badge')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Posting tagihan pemasok')
                    ->modalDescription('Nilai tagihan dan PPN masukan akan dihitung dan dikunci. '
                        .'Setelah diposting, jumlahnya tidak bisa diubah oleh siapa pun.')
                    ->visible(fn (SupplierBill $r) => $r->posted_at === null
                        && (auth()->user()?->role()->canRecordPurchases() ?? false))
                    ->action(function (SupplierBill $record) {
                        try {
                            app(SupplierBillPoster::class)->post($record, auth()->user());
                            $record->refresh();

                            Notification::make()
                                ->title("Tagihan {$record->nomor} diposting")
                                ->body('Total '.Money::format($record->total_rupiah)
                                    .' · PPN masukan '.Money::format($record->ppn_rupiah))
                                ->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title('Tidak bisa diposting')
                                ->body($e->getMessage())->danger()->send();
                        }
                    }),

                /*
                 * Paying appends to the ledger. It never touches the bill's
                 * total — the same control the customer side carries, in the
                 * opposite direction.
                 */
                Action::make('bayar')
                    ->label('Catat pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->visible(fn (SupplierBill $r) => $r->status === SupplierBill::STATUS_OPEN
                        && $r->posted_at !== null
                        && (auth()->user()?->role()->canConfirmPayment() ?? false))
                    ->schema(fn (SupplierBill $record) => [
                        TextInput::make('amount_rupiah')
                            ->label('Jumlah (Rp)')
                            ->numeric()->required()
                            ->default($record->amountOutstanding()),
                        TextInput::make('referensi')
                            ->label('Referensi transfer')
                            ->maxLength(120),
                        Select::make('bank_account_id')
                            ->label('Rekening')
                            ->options(fn () => BankAccount::query()->aktif()
                                ->get()->mapWithKeys(fn ($r) => [$r->id => $r->label()]))
                            ->default(fn () => app(BankAccounts::class)->default()->id)
                            ->required()
                            // With one rekening there is nothing to choose.
                            ->visible(fn () => BankAccount::query()->aktif()->count() > 1),
                        DateTimePicker::make('paid_at')->label('Tanggal bayar')->default(now()),
                        Textarea::make('catatan')->label('Catatan')->rows(2),
                    ])
                    ->action(function (SupplierBill $record, array $data) {
                        try {
                            app(SupplierLedger::class)->recordPayment(
                                supplier: $record->supplier,
                                amountRupiah: (int) $data['amount_rupiah'],
                                actor: auth()->user(),
                                bill: $record,
                                referensi: $data['referensi'] ?? null,
                                catatan: $data['catatan'] ?? null,
                                paidAt: $data['paid_at'] ? Carbon::parse($data['paid_at']) : null,
                                rekening: isset($data['bank_account_id'])
                                    ? BankAccount::query()->find($data['bank_account_id'])
                                    : null,
                            );

                            Notification::make()->title('Pembayaran dicatat')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title('Tidak bisa dicatat')
                                ->body($e->getMessage())->danger()->send();
                        }
                    }),

                EditAction::make()
                    ->label('Ubah')
                    ->visible(fn (SupplierBill $r) => $r->posted_at === null),
            ]);
    }
}
