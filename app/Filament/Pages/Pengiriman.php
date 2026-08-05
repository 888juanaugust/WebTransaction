<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Orders\OrderStatus;
use App\Filament\Actions\OrderTransitionActions;
use App\Models\Order;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The warehouse's own screen: what to pick, what is out for delivery, what is
 * done.
 *
 * The dashboard queue answers "what is waiting" and nothing else. A packer
 * needs more than that — which gudang, how many lines, whether the surat jalan
 * has been printed, and what shipped yesterday when a customer rings about it.
 *
 * No prices and no credit data anywhere on this page. That is not a display
 * choice: the columns are never selected, so there is nothing to leak if a
 * template changes. Warehouse staff are the one role that cannot see money, and
 * this is the screen they live on.
 */
class Pengiriman extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Pengiriman';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'pengiriman';

    protected string $view = 'filament.pages.pengiriman';

    public function getTitle(): string
    {
        return 'Pengiriman';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->role()->canPickAndShip() ?? false;
    }

    /** How many orders are waiting to be picked right now. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $waiting = Order::query()->where('status', OrderStatus::Paid)->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => Order::query()
                    ->whereIn('status', [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Completed])
                    ->with(['company', 'warehouse'])
                    ->withCount('lines')
            )
            // Paid first and oldest first — that is picking order. Sorting by
            // order number would scatter the queue across the list.
            ->defaultSort('paid_at')
            ->emptyStateHeading('Belum ada yang perlu dikirim')
            ->emptyStateDescription('Order muncul di sini setelah pembayaran diterima.')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),

                TextColumn::make('company.nama')->label('Pelanggan')->searchable()->wrap(),

                TextColumn::make('warehouse.nama')->label('Gudang')->sortable(),

                TextColumn::make('lines_count')->label('Baris'),

                TextColumn::make('total_unit')
                    ->label('Total unit')
                    ->state(fn (Order $record) => $record->lines()->sum('qty_base')),

                // Off by default. Useful when a customer rings quoting their own
                // PO number, but it pushed the actions off the right edge of a
                // laptop screen, and the actions are the point of this page.
                TextColumn::make('po_pelanggan')
                    ->label('PO pelanggan')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => match ($state) {
                        OrderStatus::Paid => 'warning',
                        OrderStatus::Shipped => 'primary',
                        default => 'success',
                    }),

                TextColumn::make('paid_at')->label('Lunas')->date('d/m/Y')->sortable()->toggleable(),

                TextColumn::make('shipped_at')
                    ->label('Dikirim')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        OrderStatus::Paid->value => 'Siap dipicking',
                        OrderStatus::Shipped->value => 'Sudah dikirim',
                        OrderStatus::Completed->value => 'Selesai',
                    ])
                    ->default(OrderStatus::Paid->value),

                SelectFilter::make('warehouse_id')
                    ->label('Gudang')
                    ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                    // A packer works one gudang; only worth asking when there
                    // is more than one.
                    ->visible(fn () => Warehouse::query()->where('aktif', true)->count() > 1),
            ])
            ->recordActions([
                // Print first, then ship, then close — the order the work
                // actually happens in.
                OrderTransitionActions::suratJalan(),
                OrderTransitionActions::kirim(),
                OrderTransitionActions::selesaikan(),
            ])
            ->paginated([25, 50, 100]);
    }
}
