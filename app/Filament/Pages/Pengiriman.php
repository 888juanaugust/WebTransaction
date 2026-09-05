<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Orders\OrderStatus;
use App\Filament\Actions\OrderTransitionActions;
use App\Filament\Navigation\SidebarGroups;
use App\Models\Order;
use App\Models\User;
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
 * Marketing's approval is what fills this screen. Under the credit operation
 * the ordinary path is confirmed → awaiting_payment with the goods leaving on
 * terms, so an approved order lands here *before* any money moves — the
 * approval is the forwarding. Prepaid orders arrive the moment they settle.
 *
 * A Gudang (storage) account sees only its own warehouse — not as a filter it
 * could clear, but in the query itself. One warehouse, one packer, and the
 * packer's queue is the warehouse's queue.
 *
 * No prices and no credit data anywhere on this page. That is not a display
 * choice: the columns are never selected, so there is nothing to leak if a
 * template changes.
 */
class Pengiriman extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static \UnitEnum|string|null $navigationGroup = SidebarGroups::GUDANG;

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

    /** How many approved orders are waiting to be picked right now. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $waiting = static::scopeToOwnWarehouse(
            Order::query()->whereIn('status', [OrderStatus::AwaitingPayment, OrderStatus::Paid]),
        )->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => static::scopeToOwnWarehouse(
                    Order::query()
                        ->whereIn('status', [
                            OrderStatus::AwaitingPayment, OrderStatus::Paid,
                            OrderStatus::Shipped, OrderStatus::Completed,
                        ])
                        ->with(['company', 'warehouse'])
                        ->withCount('lines')
                )
            )
            // Oldest approval first — that is picking order. Sorting by order
            // number would scatter the queue across the list.
            ->defaultSort('confirmed_at')
            ->emptyStateHeading('Belum ada yang perlu dikirim')
            ->emptyStateDescription('Order muncul di sini begitu disetujui marketing — '
                .'barangnya diteruskan ke gudang pengirim untuk dipacking.')
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable()->sortable(),

                TextColumn::make('company.nama')->label('Pelanggan')->searchable()->wrap(),

                TextColumn::make('warehouse.nama')
                    ->label('Gudang')
                    ->sortable()
                    // A packer's whole screen is one gudang; the column would
                    // repeat their own name down the page.
                    ->visible(fn () => auth()->user()?->warehouse_id === null),

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
                    ->formatStateUsing(fn (OrderStatus $state) => match ($state) {
                        OrderStatus::AwaitingPayment => 'Siap dikirim (kredit)',
                        OrderStatus::Paid => 'Siap dikirim (lunas)',
                        default => $state->label(),
                    })
                    ->color(fn (OrderStatus $state) => match ($state) {
                        OrderStatus::AwaitingPayment, OrderStatus::Paid => 'warning',
                        OrderStatus::Shipped => 'primary',
                        default => 'success',
                    }),

                TextColumn::make('confirmed_at')
                    ->label('Disetujui')->date('d/m/Y')->sortable()->toggleable(),

                TextColumn::make('shipped_at')
                    ->label('Dikirim')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tahap')
                    ->label('Tahap')
                    ->options([
                        'siap' => 'Siap dikirim',
                        OrderStatus::Shipped->value => 'Sudah dikirim',
                        OrderStatus::Completed->value => 'Selesai',
                    ])
                    ->default('siap')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'siap' => $query->whereIn('status', [OrderStatus::AwaitingPayment, OrderStatus::Paid]),
                        OrderStatus::Shipped->value => $query->where('status', OrderStatus::Shipped),
                        OrderStatus::Completed->value => $query->where('status', OrderStatus::Completed),
                        default => $query,
                    }),

                SelectFilter::make('warehouse_id')
                    ->label('Gudang')
                    ->options(fn () => Warehouse::query()->where('aktif', true)->pluck('nama', 'id'))
                    // A packer works one gudang and it is already in the
                    // query; only worth asking when the viewer roams.
                    ->visible(fn () => auth()->user()?->warehouse_id === null
                        && Warehouse::query()->where('aktif', true)->count() > 1),
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

    /**
     * A warehouse-bound account reads its own gudang and nothing else.
     *
     * In the query rather than a filter: a filter is a preference, and which
     * warehouse's goods an account may handle is not one.
     */
    private static function scopeToOwnWarehouse(Builder $query): Builder
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user?->role()->isWarehouseBound() && $user->warehouse_id !== null) {
            $query->where('warehouse_id', $user->warehouse_id);
        }

        return $query;
    }
}
