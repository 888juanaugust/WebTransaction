<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceListImports\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PriceListImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Berkas')
                    ->schema([
                        FileUpload::make('stored_path')
                            ->label('Berkas harga')
                            ->required()
                            ->disk('local')
                            ->directory('price-lists')
                            // The raw file is kept forever, under its own
                            // name, so any published version can be traced
                            // back to the bytes it came from.
                            ->preserveFilenames()
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                            ])
                            ->helperText('XLSX atau CSV. Berkas asli disimpan permanen.'),

                        Radio::make('format')
                            ->label('Format berkas')
                            ->options([
                                'canonical' => 'Format baku (hasil ekspor sistem ini)',
                                'supplier' => 'Berkas mentah dari supplier',
                            ])
                            ->default('canonical')
                            ->required()
                            ->helperText('Alur rutin: ekspor → ubah kolom HARGA → impor lagi.')
                            ->dehydrated(false),
                    ]),

                Section::make('Penerapan')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label('Berlaku mulai')
                            ->required()
                            ->default(now())
                            // The supplier file carries no effective date
                            // anywhere, so a human has to say when it starts.
                            ->helperText('Berkas supplier tidak memuat tanggal berlaku; harus diisi manual.'),

                        Checkbox::make('is_full_replacement')
                            ->label('Berkas ini adalah pengganti penuh')
                            ->helperText(
                                'Centang HANYA jika berkas memuat seluruh katalog. '
                                .'SKU yang tidak ada di berkas akan dinonaktifkan. '
                                .'Tanpa centang, SKU yang hilang dibiarkan apa adanya.'
                            ),
                    ]),

                Textarea::make('note')->label('Catatan')->columnSpanFull(),
            ]);
    }
}
