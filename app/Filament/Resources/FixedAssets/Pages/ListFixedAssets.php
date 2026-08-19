<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Domain\Assets\DepreciationGroup;
use App\Domain\Assets\DepreciationRunner;
use App\Domain\Assets\FixedAssetRegister;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Money;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
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
 * The register, and the two things done to it.
 *
 * **Depreciation is a button, not a cron job.** It belongs next to the person
 * closing the month, who can see the result before the period is locked — a
 * scheduled job posting into a month nobody has looked at is how a wrong figure
 * gets reported and then frozen by the close.
 */
class ListFixedAssets extends ListRecords
{
    protected static string $resource = FixedAssetResource::class;

    public function getTitle(): string
    {
        return 'Aktiva tetap';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->catatAction(),
            $this->susutkanAction(),
        ];
    }

    private function catatAction(): Action
    {
        return Action::make('catat')
            ->label('Catat aktiva')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->modalHeading('Catat aktiva tetap')
            ->modalDescription(
                'Barang yang dipakai bertahun-tahun, bukan yang dijual: kendaraan, rak, '
                .'komputer. Masuk neraca sebagai aset, lalu jadi beban sedikit demi sedikit.'
            )
            ->schema([
                TextInput::make('nama')
                    ->label('Nama aktiva')
                    ->required()
                    ->maxLength(150)
                    ->placeholder('mis. Mitsubishi L300 B 1234 XYZ'),

                Select::make('kelompok')
                    ->label('Kelompok penyusutan')
                    ->options(DepreciationGroup::options())
                    ->default(DepreciationGroup::Kelompok2->value)
                    ->required()
                    ->helperText(
                        'Kelompok pajak menurut UU PPh Pasal 11 — menentukan berapa lama '
                        .'disusutkan. Kendaraan dan rak biasanya kelompok 2; komputer kelompok 1.'
                    ),

                DatePicker::make('tanggal_perolehan')
                    ->label('Tanggal perolehan')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->required()
                    ->helperText('Penyusutan dimulai bulan ini juga, satu bulan penuh.'),

                TextInput::make('harga_perolehan_rupiah')
                    ->label('Harga perolehan')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->prefix('Rp'),

                Select::make('dibayar_dari')
                    ->label('Dibayar dari')
                    ->options(PaidFrom::options())
                    ->default(PaidFrom::Bank->value)
                    ->required(),

                Select::make('kategori')
                    ->label('Jenis')
                    ->options([
                        'kendaraan' => 'Kendaraan',
                        'peralatan' => 'Peralatan & mesin',
                        'bangunan' => 'Bangunan',
                        'lainnya' => 'Lainnya',
                    ])
                    ->default('kendaraan')
                    ->required(),

                TextInput::make('nilai_residu_rupiah')
                    ->label('Nilai residu')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix('Rp')
                    ->helperText('Biasanya nol — penyusutan pajak menyusutkan sampai habis.'),

                Textarea::make('keterangan')->label('Keterangan')->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    $asset = app(FixedAssetRegister::class)->acquire(
                        nama: $data['nama'],
                        kelompok: DepreciationGroup::from($data['kelompok']),
                        tanggal: Carbon::parse($data['tanggal_perolehan']),
                        hargaPerolehan: (int) $data['harga_perolehan_rupiah'],
                        paidFrom: PaidFrom::from($data['dibayar_dari']),
                        actor: auth()->user(),
                        kategori: $data['kategori'],
                        nilaiResidu: (int) ($data['nilai_residu_rupiah'] ?? 0),
                        keterangan: $data['keterangan'] ?? null,
                    );

                    Notification::make()
                        ->title("Aktiva {$asset->nomor} dicatat")
                        ->body('Masuk neraca. Penyusutannya dijalankan tiap akhir bulan.')
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

    private function susutkanAction(): Action
    {
        return Action::make('susutkan')
            ->label('Jalankan penyusutan')
            ->icon(Heroicon::OutlinedArrowTrendingDown)
            ->color('gray')
            ->modalHeading('Jalankan penyusutan bulanan')
            ->modalDescription(
                'Membebankan penyusutan satu bulan atas semua aktiva yang masih menyusut. '
                .'Aman dijalankan dua kali — bulan yang sudah dijalankan akan dilewati.'
            )
            ->schema([
                Select::make('periode')
                    ->label('Bulan')
                    ->options(fn () => static::periodOptions())
                    ->default(fn () => now()->subMonthNoOverflow()->format('Y-m'))
                    ->required(),
            ])
            ->action(function (array $data) {
                try {
                    $run = app(DepreciationRunner::class)->run($data['periode'], auth()->user());

                    if ($run->didNothing()) {
                        Notification::make()
                            ->title("Tidak ada yang disusutkan untuk {$run->periode}")
                            ->body('Bulan ini sudah pernah dijalankan, atau belum ada aktiva yang menyusut.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Penyusutan {$run->periode} diposting")
                        ->body("{$run->diposting} aktiva, total ".Money::format($run->totalRupiah).'.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dijalankan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * The last eighteen months, newest first, marking the ones still owing.
     *
     * Marked rather than filtered: somebody re-running a month deliberately —
     * after correcting an asset, say — needs it to still be in the list.
     */
    public static function periodOptions(): array
    {
        $outstanding = array_flip(app(DepreciationRunner::class)->outstandingPeriods(18));
        $options = [];
        $month = now()->startOfMonth();

        for ($i = 0; $i < 18; $i++) {
            $key = $month->format('Y-m');

            $options[$key] = $month->translatedFormat('F Y')
                .(isset($outstanding[$key]) ? ' — belum dijalankan' : '');

            $month->subMonth();
        }

        return $options;
    }
}
