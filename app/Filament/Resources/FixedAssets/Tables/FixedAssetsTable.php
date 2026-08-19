<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\Tables;

use App\Domain\Assets\DepreciationGroup;
use App\Domain\Assets\DepreciationSchedule;
use App\Domain\Assets\FixedAssetRegister;
use App\Domain\Expenses\PaidFrom;
use App\Domain\Money;
use App\Models\FixedAsset;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Throwable;

class FixedAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal_perolehan', 'desc')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable(),

                TextColumn::make('nama')
                    ->label('Aktiva')
                    ->description(fn (FixedAsset $r) => $r->kelompok->label())
                    ->wrap()
                    ->searchable(),

                TextColumn::make('tanggal_perolehan')
                    ->label('Diperoleh')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('harga_perolehan_rupiah')
                    ->label('Harga perolehan')
                    ->money('IDR', 100)
                    ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    ->alignEnd(),

                // Computed rather than stored: the register is the subledger,
                // and a cached figure is one more thing that can drift.
                TextColumn::make('akumulasi')
                    ->label('Akumulasi penyusutan')
                    ->state(fn (FixedAsset $r) => Money::format($r->accumulated()))
                    ->alignEnd(),

                TextColumn::make('nilai_buku')
                    ->label('Nilai buku')
                    ->state(fn (FixedAsset $r) => Money::format($r->bookValue()))
                    ->alignEnd()
                    ->weight('medium'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (FixedAsset $r) => match (true) {
                        ! $r->isActive() => 'Dilepas',
                        $r->isFullyDepreciated() => 'Habis disusutkan',
                        default => 'Aktif',
                    })
                    ->color(fn (FixedAsset $r) => match (true) {
                        ! $r->isActive() => 'gray',
                        $r->isFullyDepreciated() => 'warning',
                        default => 'success',
                    })
                    // An asset written down to nil is still in the warehouse
                    // and still ours; it just stops costing anything.
                    ->description(fn (FixedAsset $r) => $r->isActive() && ! $r->isFullyDepreciated()
                        ? 'sampai '.(new DepreciationSchedule($r))->lastPeriod()
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        FixedAsset::STATUS_AKTIF => 'Aktif',
                        FixedAsset::STATUS_DILEPAS => 'Dilepas',
                    ]),

                SelectFilter::make('kelompok')
                    ->label('Kelompok')
                    ->options(DepreciationGroup::options()),
            ])
            ->recordActions([self::lepas()])
            ->emptyStateHeading('Belum ada aktiva tetap')
            ->emptyStateDescription(
                'Kendaraan, rak gudang dan komputer dicatat di sini, bukan sebagai beban. '
                .'Tanpa itu neraca tidak menampilkan aset apa pun dan laba rugi tidak '
                .'menanggung penyusutannya — labanya jadi terlihat lebih besar.'
            );
    }

    /**
     * Selling or scrapping one.
     *
     * Both are the same journal; scrapping is a sale for nil. Offering them as
     * one action with a price field is what stops somebody looking for a
     * "hapus" button and finding the delete key instead.
     */
    private static function lepas(): Action
    {
        return Action::make('lepas')
            ->label('Lepas')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('danger')
            ->visible(fn (FixedAsset $record) => $record->isActive())
            ->modalHeading('Lepas aktiva tetap')
            ->modalDescription(
                'Dijual, ditukar tambah, atau dibuang. Harga perolehan dan akumulasi '
                .'penyusutannya keluar dari buku bersama-sama; selisihnya jadi laba atau '
                .'rugi pelepasan.'
            )
            ->schema([
                DatePicker::make('tanggal')
                    ->label('Tanggal pelepasan')
                    ->default(now())
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->maxDate(now())
                    ->required(),

                TextInput::make('harga_jual_rupiah')
                    ->label('Harga jual')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->prefix('Rp')
                    ->helperText('Isi nol kalau dibuang atau tidak laku.'),

                Select::make('proceeds_to')
                    ->label('Uangnya masuk ke')
                    ->options(PaidFrom::options())
                    ->default(PaidFrom::Bank->value),

                TextInput::make('alasan')
                    ->label('Alasan')
                    ->required()
                    ->maxLength(200)
                    ->placeholder('mis. Dijual, sudah tidak layak jalan'),
            ])
            ->action(function (FixedAsset $record, array $data) {
                try {
                    $asset = app(FixedAssetRegister::class)->dispose(
                        asset: $record,
                        tanggal: Carbon::parse($data['tanggal']),
                        hargaJual: (int) $data['harga_jual_rupiah'],
                        actor: auth()->user(),
                        alasan: $data['alasan'],
                        proceedsTo: PaidFrom::from($data['proceeds_to'] ?? PaidFrom::Bank->value),
                    );

                    Notification::make()
                        ->title("{$asset->nomor} dilepas")
                        ->body('Harga perolehan dan akumulasi penyusutannya sudah keluar dari buku.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Tidak bisa dilepas')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
