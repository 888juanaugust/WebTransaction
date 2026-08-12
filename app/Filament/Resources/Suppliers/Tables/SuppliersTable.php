<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('nama')
            ->emptyStateHeading('Belum ada pemasok')
            ->emptyStateDescription('Tambahkan pemasok sebelum mencatat penerimaan barang.')
            ->columns([
                TextColumn::make('kode')->label('Kode')->searchable()->sortable(),
                TextColumn::make('nama')->label('Nama')->searchable()->sortable(),
                TextColumn::make('nama_kontak')->label('Narahubung')->placeholder('—')->toggleable(),
                TextColumn::make('telepon')->label('Telepon')->placeholder('—')->toggleable(),
                TextColumn::make('npwp')->label('NPWP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('aktif')->label('Aktif')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('aktif')->label('Status')->placeholder('Semua'),
            ])
            ->recordActions([EditAction::make()->label('Ubah')]);
    }
}
