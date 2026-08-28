<?php

declare(strict_types=1);

namespace App\Filament\Resources\Regions\Tables;

use App\Models\Region;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RegionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('kode')
            ->emptyStateHeading('Belum ada wilayah')
            ->columns([
                TextColumn::make('kode')->label('Kode')->badge()->sortable(),

                TextColumn::make('nama')
                    ->label('Nama')
                    ->description(fn (Region $record) => $record->alamat)
                    ->searchable(),

                /*
                 * Counted without the global scope on purpose: this screen is
                 * the Owner's map of the whole company, and each row describes
                 * its own region regardless of which one is currently bound.
                 */
                TextColumn::make('staf')
                    ->label('Staf')
                    ->state(fn (Region $record) => $record->users()->where('is_active', true)->count()),

                TextColumn::make('pelanggan')
                    ->label('Pelanggan')
                    ->state(fn (Region $record) => $record->companies()->withoutGlobalScope('region')->count()),

                TextColumn::make('npwp')
                    ->label('NPWP')
                    ->placeholder('ikut pusat')
                    ->toggleable(),

                TextColumn::make('aktif')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Aktif' : 'Nonaktif')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()->label('Ubah')])
            ->toolbarActions([]);
    }
}
