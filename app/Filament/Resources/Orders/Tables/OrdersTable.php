<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Domain\Money;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
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
                // Only shows on drafts — canEdit() closes the form the moment
                // an order carries price snapshots and a stock reservation.
                EditAction::make()->label('Ubah'),

                /*
                 * Bill the customer.
                 *
                 * Confirmed means the prices are locked and the stock is held;
                 * this is the step that turns that into money owed — it issues
                 * the invoice and makes sure the buyer has a virtual account to
                 * pay into. Without it a confirmed order has no bill, and the
                 * whole AR side has nothing to work on.
                 */
                Action::make('tagihkan')
                    ->label('Tagihkan')
                    ->icon('heroicon-o-document-currency-dollar')
                    ->color('primary')
                    ->visible(fn (Order $record) => $record->status === OrderStatus::Confirmed
                        && (auth()->user()?->role()->canSeeCreditData() ?? false))
                    ->requiresConfirmation()
                    ->modalHeading('Terbitkan faktur')
                    ->modalDescription(fn (Order $record) => 'Faktur akan diterbitkan sebesar '
                        .Money::format($record->total_rupiah)
                        .' dan pelanggan akan diberi nomor Virtual Account untuk pembayaran.')
                    ->action(function (Order $record) {
                        try {
                            app(OrderStateMachine::class)->awaitPayment($record, auth()->user());
                            $record->refresh();

                            Notification::make()
                                ->title("Faktur {$record->invoice->nomor} diterbitkan")
                                ->body('Jatuh tempo '.$record->invoice->due_date->format('d/m/Y'))
                                ->success()
                                ->send();
                        } catch (\DomainException $e) {
                            Notification::make()
                                ->title('Tidak bisa ditagihkan')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
