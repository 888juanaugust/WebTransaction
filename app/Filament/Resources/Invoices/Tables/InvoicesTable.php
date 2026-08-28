<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Domain\Access\Role;
use App\Domain\Credit\DebtRemover;
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
    /**
     * Mirrors DebtRemover::assertMayInitiate so the button only shows when
     * the click would succeed; the domain class still enforces it.
     */
    private static function mayInitiateRemoval(Invoice $record): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return match ($user->role()) {
            Role::Owner => true,
            Role::Marketing => (int) $record->company->marketing_user_id === (int) $user->getKey(),
            Role::Sales => (int) $record->company->sales_user_id === (int) $user->getKey(),
            default => false,
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('order.nomor')->label('Order')->searchable(),
                /*
                 * Issue date off by default. This is an AR worklist: the date
                 * finance acts on is the due date, and carrying both pushed
                 * "Catat pembayaran" — the action this page exists for — past
                 * the right edge of the table on a 1500px screen.
                 */
                TextColumn::make('issued_on')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

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

                /*
                 * Hidden by default. There is no Coretax integration in v1, so
                 * this column is "— belum ada —" on every row — and it was wide
                 * enough to push the row actions off the right edge of the
                 * table, which cost a button that does something for a column
                 * that says nothing.
                 */
                TextColumn::make('nsfp')
                    ->label('NSFP')
                    ->placeholder('— belum ada —')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                 * The document itself. Gated on canSeeCreditData() so the
                 * action does not appear for a role the route would refuse —
                 * the controller enforces it either way, but an action that
                 * 403s when clicked is a bug report waiting to be filed.
                 */
                Action::make('cetak_faktur')
                    ->label('Cetak faktur')
                    // Icon only, with the label as its tooltip. Spelled out, it
                    // was wide enough to shove "Catat pembayaran" — the action
                    // finance uses all day — off the right edge of the table.
                    ->iconButton()
                    ->tooltip('Cetak faktur')
                    ->icon('heroicon-o-printer')
                    ->visible(fn () => auth()->user()->role()->canSeeCreditData())
                    ->url(fn (Invoice $record) => route('dokumen.faktur', $record))
                    ->openUrlInNewTab(),

                /*
                 * Manual payment entry, for transfers that did not come
                 * through the gateway. Restricted to Finance and Owner, and it
                 * appends to the ledger — it never edits the invoice amount.
                 */
                /*
                 * The claim that a debt was paid outside the system — cash
                 * handed over on a store visit. Only the customer's own team
                 * (their sales or marketing, or the Owner) can file it, and
                 * filing moves no money: finance verifies before anything
                 * posts.
                 */
                Action::make('ajukan_penghapusan')
                    ->label('Ajukan pelunasan')
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->visible(fn (Invoice $record) => $record->status === Invoice::STATUS_OPEN
                        && self::mayInitiateRemoval($record))
                    ->modalHeading('Ajukan pelunasan piutang')
                    ->modalDescription('Pengajuan ini menunggu verifikasi finance — tidak ada yang berubah sebelum mereka menyetujui.')
                    ->schema(fn (Invoice $record) => [
                        TextInput::make('amount_rupiah')
                            ->label('Jumlah diterima (Rp)')
                            ->numeric()
                            ->required()
                            ->default($record->amountOutstanding())
                            ->helperText('Sisa tagihan: '.Money::format($record->amountOutstanding())),
                        Textarea::make('alasan')
                            ->label('Bagaimana uangnya diterima')
                            ->helperText('Di mana, kapan, dalam bentuk apa — inilah yang diverifikasi finance.')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        try {
                            app(DebtRemover::class)->initiate(
                                $record,
                                auth()->user(),
                                (int) $data['amount_rupiah'],
                                $data['alasan'],
                            );

                            Notification::make()
                                ->title('Pengajuan terkirim')
                                ->body('Menunggu verifikasi finance.')
                                ->success()
                                ->send();
                        } catch (\DomainException $e) {
                            Notification::make()
                                ->title('Tidak bisa diajukan')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

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
