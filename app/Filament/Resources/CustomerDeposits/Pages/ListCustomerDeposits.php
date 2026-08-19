<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerDeposits\Pages;

use App\Domain\Billing\CustomerDepositRegister;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Money;
use App\Filament\Resources\CustomerDeposits\CustomerDepositResource;
use App\Models\Company;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

class ListCustomerDeposits extends ListRecords
{
    protected static string $resource = CustomerDepositResource::class;

    public function getTitle(): string
    {
        return 'Uang muka pelanggan';
    }

    protected function getHeaderActions(): array
    {
        return [$this->catatAction()];
    }

    private function catatAction(): Action
    {
        return Action::make('catat')
            ->label('Catat uang muka')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading('Catat uang muka pelanggan')
            /*
             * The distinction staff get wrong. Both are money in with no
             * invoice attached, and they belong in different accounts: an
             * unmatched payment is a debt we know exists and cannot yet name,
             * a deposit is a debt that has not been incurred.
             */
            ->modalDescription(
                'Untuk uang yang masuk sebelum ada faktur. Kalau fakturnya sudah terbit dan '
                .'Anda hanya belum tahu yang mana, itu pembayaran biasa — catat lewat '
                .'Pembayaran, bukan di sini.'
            )
            ->schema([
                Select::make('company_id')
                    ->label('Pelanggan')
                    ->options(fn () => Company::query()->orderBy('nama')->pluck('nama', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live(),

                /*
                 * Scoped to the chosen customer. Optional because the order
                 * often does not exist yet — a customer transfers a round
                 * number to open an account before anybody types a line.
                 */
                Select::make('order_id')
                    ->label('Untuk pesanan (opsional)')
                    ->options(fn (Get $get) => $get('company_id')
                        ? Order::query()
                            ->where('company_id', $get('company_id'))
                            ->orderByDesc('created_at')
                            ->limit(50)
                            ->pluck('nomor', 'id')
                            ->all()
                        : [])
                    ->searchable()
                    ->helperText('Kosongkan kalau pesanannya belum dibuat.'),

                DatePicker::make('tanggal')
                    ->label('Tanggal terima')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->required(),

                TextInput::make('jumlah_rupiah')
                    ->label('Jumlah')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->prefix('Rp'),

                Select::make('diterima_di')
                    ->label('Masuk ke')
                    ->options(PaidFrom::options())
                    ->default(PaidFrom::Bank->value)
                    ->required()
                    ->helperText('Uang muka sering dibayar tunai di tempat, jadi ini ditanya.'),

                TextInput::make('referensi')
                    ->label('Referensi')
                    ->maxLength(60)
                    ->helperText('Nomor transfer atau nomor kuitansi.'),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    $deposit = app(CustomerDepositRegister::class)->receive(
                        company: Company::query()->findOrFail($data['company_id']),
                        jumlahRupiah: (int) $data['jumlah_rupiah'],
                        diterimaDi: PaidFrom::from($data['diterima_di']),
                        tanggal: Carbon::parse($data['tanggal']),
                        actor: auth()->user(),
                        order: isset($data['order_id'])
                            ? Order::query()->find($data['order_id'])
                            : null,
                        referensi: $data['referensi'] ?? null,
                        catatan: $data['catatan'] ?? null,
                    );

                    Notification::make()
                        ->title("Uang muka {$deposit->nomor} dicatat")
                        ->body(Money::format((int) $deposit->jumlah_rupiah)
                            .' masuk sebagai utang kita ke pelanggan, bukan pengurang piutang.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dicatat')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
