<?php

declare(strict_types=1);

namespace App\Filament\Resources\SalesExpenseClaims\Tables;

use App\Domain\Expenses\ClaimStatus;
use App\Domain\Money;
use App\Filament\Actions\SalesExpenseClaimActions;
use App\Models\SalesExpenseClaim;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesExpenseClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tanggal')->label('Tanggal')->date('d/m/Y')->sortable(),
                TextColumn::make('sales.name')->label('Sales')->searchable(),
                TextColumn::make('amount_rupiah')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('keterangan')->label('Untuk apa')->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ClaimStatus $state) => $state->label())
                    ->color(fn (ClaimStatus $state) => $state->color()),
                TextColumn::make('decider.name')
                    ->label('Diputus oleh')
                    ->placeholder('—')
                    ->description(fn (SalesExpenseClaim $record) => $record->keputusan_catatan),
                TextColumn::make('expense.nomor')
                    ->label('Beban')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ClaimStatus::cases())
                        ->mapWithKeys(fn (ClaimStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                SalesExpenseClaimActions::setujui(),
                SalesExpenseClaimActions::tolak(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
