<?php

declare(strict_types=1);

namespace App\Filament\Resources\StoreVisits\Tables;

use App\Domain\Visits\StoreVisits;
use App\Models\StoreVisit;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StoreVisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('visited_at')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('sales.name')->label('Sales')->searchable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),

                ImageColumn::make('foto_path')
                    ->label('Foto')
                    ->disk(StoreVisits::DISK)
                    ->height(48)
                    ->square(),

                TextColumn::make('lokasi')
                    ->label('Lokasi')
                    ->state(fn (StoreVisit $record) => $record->latitude !== null
                        ? number_format((float) $record->latitude, 5).', '.number_format((float) $record->longitude, 5)
                        : '—')
                    // The coordinates as a link the reviewer can actually
                    // check, rather than digits to squint at.
                    ->url(fn (StoreVisit $record) => $record->latitude !== null
                        ? "https://www.google.com/maps?q={$record->latitude},{$record->longitude}"
                        : null)
                    ->openUrlInNewTab(),

                TextColumn::make('catatan')
                    ->label('Catatan')
                    ->wrap()
                    ->placeholder('—'),

                TextColumn::make('foto_dihapus_pada')
                    ->label('Foto dihapus')
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('visited_at', 'desc');
    }
}
