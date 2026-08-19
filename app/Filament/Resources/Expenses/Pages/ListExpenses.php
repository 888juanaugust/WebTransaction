<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Pages;

use App\Domain\Accounting\AccountCode;
use App\Domain\Expenses\ExpenseRecorder;
use App\Domain\Expenses\PaidFrom;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Account;
use App\Models\Supplier;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The expense register, and the one action that adds to it.
 *
 * Recording is a single form that posts immediately — no draft. An expense is
 * entered after the money has already gone, so a draft stage would only be a
 * way for real spending to sit outside the books, which is the exact problem
 * this screen exists to fix.
 */
class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    public function getTitle(): string
    {
        return 'Beban';
    }

    protected function getHeaderActions(): array
    {
        return [$this->catatAction()];
    }

    private function catatAction(): Action
    {
        return Action::make('catat')
            ->label('Catat beban')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading('Catat beban')
            ->modalDescription(
                'Uang keluar untuk hal selain barang dagangan: sewa, gaji, listrik, '
                .'BBM, ongkos kirim. Langsung masuk jurnal.'
            )
            ->schema([
                DatePicker::make('tanggal')
                    ->label('Tanggal')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->required(),

                Select::make('account')
                    ->label('Jenis beban')
                    ->options(fn () => static::expenseAccounts())
                    ->searchable()
                    ->required()
                    ->helperText('Kalau sebuah biaya muncul tiap bulan tapi belum ada akunnya, minta ditambahkan.'),

                TextInput::make('amount_rupiah')
                    ->label('Nilai')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->prefix('Rp'),

                Select::make('dibayar_dari')
                    ->label('Dibayar dari')
                    ->options(PaidFrom::options())
                    ->default(PaidFrom::Bank->value)
                    ->required()
                    ->helperText('Tunai keluar dari Kas; transfer keluar dari Bank dan akan muncul di rekening koran.'),

                TextInput::make('keterangan')
                    ->label('Keterangan')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('mis. Sewa gudang Agustus 2026'),

                Select::make('supplier_id')
                    ->label('Dibayar ke (opsional)')
                    ->options(fn () => Supplier::query()->orderBy('nama')->pluck('nama', 'id')->all())
                    ->searchable(),

                TextInput::make('referensi')
                    ->label('Referensi (opsional)')
                    ->maxLength(60)
                    ->placeholder('Nomor kuitansi atau tagihan'),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    $expense = app(ExpenseRecorder::class)->record(
                        tanggal: Carbon::parse($data['tanggal']),
                        accountCode: $data['account'],
                        paidFrom: PaidFrom::from($data['dibayar_dari']),
                        amountRupiah: (int) $data['amount_rupiah'],
                        keterangan: $data['keterangan'],
                        actor: auth()->user(),
                        supplier: isset($data['supplier_id'])
                            ? Supplier::query()->find($data['supplier_id'])
                            : null,
                        referensi: $data['referensi'] ?? null,
                        catatan: $data['catatan'] ?? null,
                    );

                    Notification::make()
                        ->title("Beban {$expense->nomor} dicatat")
                        ->body('Jurnalnya sudah diposting.')
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

    /**
     * Which accounts the form offers.
     *
     * Operating expense only. Cost of sales is excluded because it comes from
     * stock movements, and the recorder refuses it anyway — this just means
     * nobody is offered a choice the system will then reject.
     */
    public static function expenseAccounts(): array
    {
        $hppParent = Account::byCode(AccountCode::HARGA_POKOK_PENJUALAN)->parent_id;

        return Account::query()
            ->where('dapat_diposting', true)
            ->where('tipe', 'beban')
            ->where('parent_id', '!=', $hppParent)
            ->orderBy('kode')
            ->get()
            ->mapWithKeys(fn (Account $a) => [$a->kode => "{$a->kode} — {$a->nama}"])
            ->all();
    }
}
