<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Domain\Money;
use App\Domain\Orders\OrderStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $showPrices = auth()->user()?->role()->canSeePrices() ?? false;

        return $table
            ->columns(array_values(array_filter([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),

                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => match ($state) {
                        OrderStatus::Completed, OrderStatus::Paid => 'success',
                        OrderStatus::Rejected, OrderStatus::Expired => 'danger',
                        OrderStatus::Draft => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('warehouse.nama')->label('Gudang')->toggleable(),

                // Warehouse staff must not see prices, so the money column is
                // not merely hidden in the UI — it is never added to the table.
                // An order only has a total once it is confirmed and the lines
                // take their price snapshot. Before that "Rp 0" would read as
                // a free order rather than an unpriced one.
                $showPrices
                    ? TextColumn::make('total_rupiah')
                        ->label('Total')
                        ->formatStateUsing(fn (?int $state, $record) => $record->confirmed_at === null
                            ? '— belum dihitung —'
                            : Money::format((int) $state))
                        ->sortable()
                    : null,

                TextColumn::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i')->sortable(),
            ])))
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $s) => [$s->value => $s->label()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
