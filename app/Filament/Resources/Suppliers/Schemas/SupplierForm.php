<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Identitas')
                    ->columns(3)
                    ->schema([
                        TextInput::make('kode')
                            ->label('Kode')
                            ->required()
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->helperText('Dipakai untuk mencari cepat. Contoh: SUP-0001.'),

                        TextInput::make('nama')->label('Nama pemasok')->required()->columnSpan(2),

                        TextInput::make('npwp')->label('NPWP')->maxLength(25),

                        Toggle::make('aktif')->label('Aktif')->default(true),
                    ]),

                Section::make('Kontak')
                    ->columns(3)
                    ->schema([
                        TextInput::make('nama_kontak')->label('Nama narahubung'),
                        TextInput::make('telepon')->label('Telepon')->tel(),
                        TextInput::make('email')->label('Email')->email(),
                        Textarea::make('alamat')->label('Alamat')->rows(2)->columnSpanFull(),
                    ]),

                Textarea::make('catatan')->label('Catatan')->rows(2),
            ]);
    }
}
