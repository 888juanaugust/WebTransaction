<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Tables;

use App\Domain\Credit\CreditChecker;
use App\Domain\Money;
use App\Models\Company;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kode')->label('Kode')->searchable()->sortable(),
                TextColumn::make('nama')->label('Nama')->searchable()->sortable(),

                TextColumn::make('jenis_usaha')
                    ->label('Jenis')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'bengkel' => 'Bengkel',
                        'toko_sparepart' => 'Toko sparepart',
                        'distributor' => 'Distributor',
                        default => $state,
                    }),

                TextColumn::make('kota')->label('Kota')->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        Company::STATUS_PENDING => 'Menunggu persetujuan',
                        Company::STATUS_ACTIVE => 'Aktif',
                        Company::STATUS_SUSPENDED => 'Ditangguhkan',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        Company::STATUS_ACTIVE => 'success',
                        Company::STATUS_PENDING => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('credit_limit_rupiah')
                    ->label('Limit kredit')
                    ->formatStateUsing(fn (int $state) => Money::format($state))
                    ->sortable(),

                // Computed from the ledgers, not stored — a cached "available
                // credit" column would be one more thing to drift.
                TextColumn::make('sisa_kredit')
                    ->label('Sisa kredit')
                    ->state(fn (Company $record) => Money::format(
                        app(CreditChecker::class)->available($record)
                    )),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        Company::STATUS_PENDING => 'Menunggu persetujuan',
                        Company::STATUS_ACTIVE => 'Aktif',
                        Company::STATUS_SUSPENDED => 'Ditangguhkan',
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah'),
            ])
            ->defaultSort('nama');
    }
}
