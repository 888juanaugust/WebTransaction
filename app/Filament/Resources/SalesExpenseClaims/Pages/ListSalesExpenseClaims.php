<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesExpenseClaims\Pages;

use App\Domain\Access\Role;
use App\Domain\Expenses\SalesExpenseClaims;
use App\Filament\Resources\SalesExpenseClaims\SalesExpenseClaimResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListSalesExpenseClaims extends ListRecords
{
    protected static string $resource = SalesExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * The claim form: what, when, how much. Sales only — finance
             * records their own expenses straight onto the Beban screen and
             * does not claim from themselves.
             */
            Action::make('ajukan')
                ->label('Ajukan biaya')
                ->icon('heroicon-o-plus')
                ->visible(fn () => auth()->user()?->role() === Role::Sales)
                ->modalHeading('Ajukan biaya ekspedisi')
                ->modalDescription('Diverifikasi finance dulu — masuk buku hanya setelah mereka menyetujui.')
                ->schema([
                    DatePicker::make('tanggal')
                        ->label('Tanggal')
                        ->default(today())
                        ->maxDate(today())
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),
                    TextInput::make('amount_rupiah')
                        ->label('Jumlah (Rp)')
                        ->numeric()
                        ->required(),
                    Textarea::make('keterangan')
                        ->label('Untuk apa')
                        ->helperText('Bensin, tol, parkir, kirim barang — sebut rute atau pelanggannya.')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data) {
                    try {
                        app(SalesExpenseClaims::class)->file(
                            auth()->user(),
                            Carbon::parse($data['tanggal']),
                            (int) $data['amount_rupiah'],
                            $data['keterangan'],
                        );

                        Notification::make()
                            ->title('Klaim terkirim')
                            ->body('Menunggu verifikasi finance.')
                            ->success()
                            ->send();
                    } catch (DomainException $e) {
                        Notification::make()
                            ->title('Tidak bisa diajukan')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
