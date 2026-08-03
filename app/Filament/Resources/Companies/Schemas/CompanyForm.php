<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Schemas;

use App\Models\Company;
use App\Models\PriceTier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas')
                    ->columns(2)
                    ->schema([
                        TextInput::make('kode')
                            ->label('Kode pelanggan')
                            ->required()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true),

                        TextInput::make('nama')
                            ->label('Nama usaha')
                            ->required()
                            ->maxLength(255),

                        Select::make('jenis_usaha')
                            ->label('Jenis usaha')
                            ->options([
                                'bengkel' => 'Bengkel',
                                'toko_sparepart' => 'Toko sparepart',
                                'distributor' => 'Distributor',
                            ])
                            ->required(),

                        TextInput::make('nama_kontak')->label('Nama kontak'),
                        TextInput::make('telepon')->label('Telepon')->tel(),
                        TextInput::make('email')->label('Email')->email(),
                    ]),

                Section::make('Data pajak')
                    ->description('Dipakai apa adanya pada faktur pajak.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('npwp')->label('NPWP')->maxLength(25),
                        TextInput::make('nama_wajib_pajak')->label('Nama wajib pajak'),
                        Textarea::make('alamat_pajak')->label('Alamat pajak')->columnSpanFull(),
                    ]),

                Section::make('Pengiriman')
                    ->columns(2)
                    ->schema([
                        Textarea::make('alamat_kirim')->label('Alamat kirim')->columnSpanFull(),
                        TextInput::make('kota')->label('Kota'),
                    ]),

                Section::make('Kredit dan harga')
                    ->columns(2)
                    ->schema([
                        Select::make('price_tier_id')
                            ->label('Tingkat harga')
                            ->options(fn () => PriceTier::where('aktif', true)->pluck('nama', 'id'))
                            ->searchable()
                            ->placeholder('Harga list'),

                        Select::make('status')
                            ->label('Status')
                            ->options([
                                Company::STATUS_PENDING => 'Menunggu persetujuan',
                                Company::STATUS_ACTIVE => 'Aktif',
                                Company::STATUS_SUSPENDED => 'Ditangguhkan',
                            ])
                            ->required(),

                        // A credit limit change is a money-affecting action:
                        // only Finance and Owner may touch it, and the change
                        // is written to the audit log on save.
                        TextInput::make('credit_limit_rupiah')
                            ->label('Limit kredit (Rp)')
                            ->numeric()
                            ->default(0)
                            ->disabled(fn () => ! auth()->user()->role()->canOverrideCreditLimit())
                            ->helperText('Hanya Keuangan dan Pemilik yang bisa mengubah.'),

                        TextInput::make('payment_terms_days')
                            ->label('Termin (hari)')
                            ->numeric()
                            ->default(30),
                    ]),

                Textarea::make('catatan')->label('Catatan')->columnSpanFull(),
            ]);
    }
}
