<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerDeposits\Tables;

use App\Domain\Billing\CustomerDepositRegister;
use App\Domain\Money;
use App\Models\Company;
use App\Models\CustomerDeposit;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Throwable;

class CustomerDepositsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal', 'desc')
            ->columns([
                TextColumn::make('nomor')
                    ->label('Nomor')
                    ->searchable()
                    ->description(fn (CustomerDeposit $r) => $r->referensi),

                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),

                TextColumn::make('company.nama')
                    ->label('Pelanggan')
                    ->description(fn (CustomerDeposit $r) => $r->order
                        ? 'untuk '.$r->order->nomor
                        : 'belum terikat pesanan')
                    ->searchable(),

                TextColumn::make('jumlah_rupiah')
                    ->label('Disetor')
                    ->state(fn (CustomerDeposit $r) => Money::format((int) $r->jumlah_rupiah))
                    ->description(fn (CustomerDeposit $r) => $r->diterima_di->label())
                    ->alignEnd(),

                /*
                 * The figure the screen exists for. What was paid is history;
                 * what is left is a liability somebody has to do something
                 * about, so it gets the emphasis.
                 */
                TextColumn::make('sisa')
                    ->label('Sisa')
                    ->state(fn (CustomerDeposit $r) => Money::format($r->sisaRupiah()))
                    ->description(fn (CustomerDeposit $r) => $r->terpakai_rupiah > 0
                        ? 'dipakai '.Money::format((int) $r->terpakai_rupiah)
                        : null)
                    ->weight('medium')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CustomerDeposit $r) => $r->isHeld() ? 'Dipegang' : 'Habis')
                    ->color(fn (CustomerDeposit $r) => $r->isHeld() ? 'warning' : 'success'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options([
                    CustomerDeposit::STATUS_HELD => 'Masih dipegang',
                    CustomerDeposit::STATUS_CLOSED => 'Sudah habis',
                ]),

                SelectFilter::make('company_id')
                    ->label('Pelanggan')
                    ->options(fn () => Company::query()->orderBy('nama')->pluck('nama', 'id')->all())
                    ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([self::pakai(), self::kembalikan()])->tooltip('Tindakan'),
            ])
            ->emptyStateHeading('Belum ada uang muka')
            ->emptyStateDescription(
                'Untuk uang yang masuk sebelum ada faktur — pelanggan baru tanpa limit kredit, '
                .'atau pesanan khusus yang dijamin di muka. Kalau fakturnya sudah ada, itu '
                .'pembayaran biasa, bukan uang muka.'
            );
    }

    private static function pakai(): Action
    {
        return Action::make('pakai')
            ->label('Pakai untuk faktur')
            ->icon(Heroicon::OutlinedArrowRightCircle)
            ->visible(fn (CustomerDeposit $record) => $record->isHeld())
            /*
             * Disabled rather than hidden when the customer has nothing open.
             * A deposit taken before the order exists is the ordinary case, so
             * this state is common — and opening a form whose only required
             * field has no options in it is a button that promised something
             * it cannot do. Hidden would leave people hunting for it instead.
             */
            ->disabled(fn (CustomerDeposit $record) => ! self::hasOpenInvoice($record))
            ->tooltip(fn (CustomerDeposit $record) => self::hasOpenInvoice($record)
                ? null
                : 'Belum ada faktur terbuka untuk pelanggan ini. Uang mukanya menunggu sampai '
                    .'pesanannya difakturkan.')
            ->modalHeading('Pakai uang muka')
            ->modalDescription('Utang pelanggan turun sebesar ini. Tidak ada uang yang bergerak — '
                .'uangnya sudah masuk waktu uang muka diterima.')
            ->schema(fn (CustomerDeposit $record) => [
                /*
                 * Scoped to this customer's open invoices. Offering a settled
                 * one, or somebody else's, would mean offering a choice the
                 * register then refuses.
                 */
                Select::make('invoice_id')
                    ->label('Faktur')
                    ->options(fn () => Invoice::query()
                        ->where('company_id', $record->company_id)
                        ->where('status', Invoice::STATUS_OPEN)
                        ->orderByDesc('issued_on')
                        ->get()
                        ->mapWithKeys(fn (Invoice $i) => [
                            $i->id => "{$i->nomor} — kurang ".Money::format($i->amountOutstanding()),
                        ])
                        ->all())
                    ->searchable()
                    ->required(),

                TextInput::make('jumlah_rupiah')
                    ->label('Dipakai')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue($record->sisaRupiah())
                    ->default($record->sisaRupiah())
                    ->required()
                    ->prefix('Rp')
                    ->helperText('Sisa uang muka '.Money::format($record->sisaRupiah()).'.'),
            ])
            ->action(function (CustomerDeposit $record, array $data) {
                try {
                    $movement = app(CustomerDepositRegister::class)->apply(
                        deposit: $record,
                        invoice: Invoice::query()->findOrFail($data['invoice_id']),
                        jumlahRupiah: (int) $data['jumlah_rupiah'],
                        actor: auth()->user(),
                        tanggal: Carbon::now(),
                    );

                    Notification::make()
                        ->title('Uang muka dipakai')
                        ->body(Money::format((int) $movement->jumlah_rupiah).' masuk ke faktur '
                            .$movement->invoice?->nomor.'. Sisa '
                            .Money::format($record->refresh()->sisaRupiah()).'.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dipakai')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /** Is there anything this deposit could be put against today? */
    public static function hasOpenInvoice(CustomerDeposit $deposit): bool
    {
        return Invoice::query()
            ->where('company_id', $deposit->company_id)
            ->where('status', Invoice::STATUS_OPEN)
            ->exists();
    }

    private static function kembalikan(): Action
    {
        return Action::make('kembalikan')
            ->label('Kembalikan')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (CustomerDeposit $record) => $record->isHeld())
            ->modalHeading('Kembalikan uang muka')
            ->modalDescription('Uang benar-benar keluar dari kas atau bank. Akan muncul di '
                .'rekonsiliasi bank sebagai pengeluaran.')
            ->schema(fn (CustomerDeposit $record) => [
                TextInput::make('jumlah_rupiah')
                    ->label('Dikembalikan')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue($record->sisaRupiah())
                    ->default($record->sisaRupiah())
                    ->required()
                    ->prefix('Rp'),

                TextInput::make('alasan')
                    ->label('Alasan')
                    ->required()
                    ->maxLength(200)
                    ->placeholder('mis. Pesanan dibatalkan pelanggan'),
            ])
            ->action(function (CustomerDeposit $record, array $data) {
                try {
                    app(CustomerDepositRegister::class)->refund(
                        deposit: $record,
                        jumlahRupiah: (int) $data['jumlah_rupiah'],
                        actor: auth()->user(),
                        alasan: $data['alasan'],
                        tanggal: Carbon::now(),
                    );

                    Notification::make()
                        ->title('Uang muka dikembalikan')
                        ->body('Sisa '.Money::format($record->refresh()->sisaRupiah()).'.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dikembalikan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
