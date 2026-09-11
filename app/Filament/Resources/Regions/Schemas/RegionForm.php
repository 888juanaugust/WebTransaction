<?php

declare(strict_types=1);

namespace App\Filament\Resources\Regions\Schemas;

use App\Models\Region;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RegionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Identitas wilayah')
                    ->columns(3)
                    ->schema([
                        TextInput::make('kode')
                            ->label('Kode')
                            ->required()
                            ->maxLength(8)
                            ->alphaNum()
                            ->unique(table: Region::class, ignoreRecord: true)
                            /*
                             * Uppercased on the way in because it goes into
                             * every document number this region issues —
                             * INV-JKT-202608-0001 — and a register mixing jkt
                             * and JKT reads as two branches.
                             */
                            ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state))
                            ->disabledOn('edit')
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'Tidak bisa diubah: kode ini sudah tercetak di setiap nomor dokumen wilayah ini.'
                                : 'Singkat, mis. JKT atau SBY. Masuk ke setiap nomor dokumen dan tidak bisa diubah lagi.'),

                        TextInput::make('nama')
                            ->label('Nama')
                            ->required()
                            ->columnSpan(2),

                        Textarea::make('alamat')->label('Alamat')->rows(2)->columnSpan(2),

                        TextInput::make('telepon')->label('Telepon')->tel(),

                        Toggle::make('aktif')
                            ->label('Aktif')
                            ->default(true)
                            ->helperText('Wilayah nonaktif hilang dari pilihan, datanya tetap tersimpan.'),
                    ]),

                Section::make('Lokasi di peta')
                    ->description('Dua angka dari peta, supaya halaman Kontak situs publik bisa '
                        .'menunjukkan cabang terdekat ke pengunjung. Buka Google Maps, klik kanan '
                        .'titik cabang, salin angkanya. Kosongkan bila belum tahu — cabang tetap '
                        .'tampil di daftar, hanya tidak ikut dihitung jaraknya.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('lintang')->label('Lintang (latitude)')
                            ->numeric()->minValue(-90)->maxValue(90)->step(0.000001)
                            ->placeholder('-7.257472'),
                        TextInput::make('bujur')->label('Bujur (longitude)')
                            ->numeric()->minValue(-180)->maxValue(180)->step(0.000001)
                            ->placeholder('112.752090'),
                    ]),

                Section::make('Identitas pajak')
                    ->description('Kosongkan bila wilayah ini menerbitkan faktur atas nama pusat. '
                        .'Isi hanya bila wilayah ini badan usaha sendiri dengan NPWP sendiri.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('npwp')->label('NPWP')->maxLength(25),
                        TextInput::make('nama_wajib_pajak')->label('Nama wajib pajak'),
                        Textarea::make('alamat_pajak')->label('Alamat pajak')->rows(2)->columnSpanFull(),
                    ]),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ]);
    }
}
