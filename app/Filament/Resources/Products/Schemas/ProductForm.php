<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas produk')
                    ->columns(2)
                    ->schema([
                        TextInput::make('kode')
                            ->label('KODE')
                            ->required()
                            ->maxLength(60)
                            ->unique(ignoreRecord: true)
                            // KODE is the primary key and the join key for the
                            // whole ledger; changing it on a live SKU orphans
                            // its stock and order history.
                            ->disabledOn('edit')
                            ->helperText('Kunci utama. Tidak bisa diubah setelah dibuat.'),

                        Select::make('merk')
                            ->label('Merk')
                            ->options(array_combine(
                                config('pricelist.known_brands'),
                                config('pricelist.known_brands'),
                            ))
                            ->required(),

                        Select::make('kategori')
                            ->label('Kategori')
                            ->options(array_combine(
                                config('pricelist.known_categories'),
                                config('pricelist.known_categories'),
                            ))
                            ->required(),

                        TextInput::make('tipe_produk')->label('Tipe produk'),
                        TextInput::make('mobil')->label('Mobil'),
                        TextInput::make('part_number')->label('Part number'),
                    ]),

                Section::make('Satuan')
                    ->description('Satuan dasar adalah satuan yang dihitung di ledger stok.')
                    ->columns(2)
                    ->schema([
                        Select::make('satuan_dasar')
                            ->label('Satuan dasar')
                            ->options(['PCS' => 'PCS', 'SET' => 'SET'])
                            ->required()
                            ->default('PCS'),

                        TextInput::make('qty_per_ctn')
                            ->label('Isi per dus')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->default(1),
                    ]),

                Textarea::make('description')->label('Deskripsi')->columnSpanFull(),
                Textarea::make('catatan')->label('Catatan')->columnSpanFull(),

                Toggle::make('aktif')->label('Aktif')->default(true),
            ]);
    }
}
