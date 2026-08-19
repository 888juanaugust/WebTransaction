<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Tables;

use App\Domain\Expenses\ExpenseRecorder;
use App\Domain\Expenses\PaidFrom;
use App\Models\Account;
use App\Models\Expense;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal', 'desc')
            ->columns([
                TextColumn::make('nomor')
                    ->label('Nomor')
                    ->searchable()
                    ->description(fn (Expense $r) => $r->isReversal() ? 'koreksi' : null),

                TextColumn::make('tanggal')
                    ->label('Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('account.nama')
                    ->label('Akun')
                    ->description(fn (Expense $r) => $r->account?->kode)
                    ->searchable(),

                TextColumn::make('keterangan')
                    ->label('Keterangan')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('dibayar_dari')
                    ->label('Dibayar dari')
                    ->formatStateUsing(fn (PaidFrom $state) => $state->label()),

                TextColumn::make('amount_rupiah')
                    ->label('Nilai')
                    ->money('IDR', 100)
                    ->alignEnd()
                    // A reversal is the same amount read the other way, and
                    // showing both as positive makes a corrected pair look like
                    // twice the spending.
                    ->formatStateUsing(fn (Expense $r, $state) => ($r->isReversal() ? '−' : '').
                        'Rp '.number_format((int) $state, 0, ',', '.'))
                    ->color(fn (Expense $r) => $r->isReversal() ? 'danger' : null),

                TextColumn::make('createdBy.name')
                    ->label('Dicatat oleh')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('account_id')
                    ->label('Akun')
                    ->options(fn () => Account::query()
                        ->where('kode', 'like', '6-%')
                        ->where('dapat_diposting', true)
                        ->orderBy('kode')
                        ->pluck('nama', 'id')
                        ->all()),

                SelectFilter::make('dibayar_dari')
                    ->label('Dibayar dari')
                    ->options(PaidFrom::options()),
            ])
            ->recordActions([self::koreksi()])
            ->emptyStateHeading('Belum ada beban dicatat')
            ->emptyStateDescription(
                'Sewa, gaji, listrik, BBM dan ongkos kirim masuk lewat "Catat beban". '
                .'Tanpa itu laba rugi hanya menampilkan penjualan dan harga pokok, '
                .'dan labanya terlihat lebih besar dari yang sebenarnya.'
            );
    }

    /**
     * Correcting one.
     *
     * Hidden once a correction exists, and on the correction itself — the
     * recorder refuses both anyway, and an action that is offered and then
     * refuses is a screen that lied.
     */
    private static function koreksi(): Action
    {
        return Action::make('koreksi')
            ->label('Koreksi')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Expense $record) => ! $record->isReversal() && ! $record->isReversed())
            ->schema([
                TextInput::make('alasan')
                    ->label('Alasan koreksi')
                    ->required()
                    ->maxLength(200)
                    ->helperText('Muncul di jurnal dan di log audit.'),
            ])
            ->action(function (Expense $record, array $data) {
                try {
                    $reversal = app(ExpenseRecorder::class)
                        ->reverse($record, auth()->user(), $data['alasan']);

                    Notification::make()
                        ->title("Dikoreksi lewat {$reversal->nomor}")
                        ->body('Yang salah tetap tercatat; koreksinya berdiri di sebelahnya.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dikoreksi')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
