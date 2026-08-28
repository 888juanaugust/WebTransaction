<?php

declare(strict_types=1);

namespace App\Filament\Resources\DebtRemovals\Tables;

use App\Domain\Credit\DebtRemovalStatus;
use App\Domain\Money;
use App\Filament\Actions\DebtRemovalActions;
use App\Models\DebtRemoval;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DebtRemovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('invoice.nomor')
                    ->label('Faktur')
                    ->searchable(),

                TextColumn::make('company.nama')
                    ->label('Pelanggan')
                    ->searchable(),

                TextColumn::make('amount_rupiah')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (int $state) => Money::format($state)),

                /*
                 * The claim itself, in full. This column is the thing finance
                 * reads before deciding, so it wraps rather than truncates.
                 */
                TextColumn::make('alasan')
                    ->label('Bagaimana diterima')
                    ->wrap(),

                TextColumn::make('initiator.name')
                    ->label('Diajukan oleh'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (DebtRemovalStatus $state) => $state->label())
                    ->color(fn (DebtRemovalStatus $state) => $state->color()),

                TextColumn::make('decider.name')
                    ->label('Diputus oleh')
                    ->placeholder('—')
                    ->description(fn (DebtRemoval $record) => $record->keputusan_catatan),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(DebtRemovalStatus::cases())
                        ->mapWithKeys(fn (DebtRemovalStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                DebtRemovalActions::setujui(),
                DebtRemovalActions::tolak(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
