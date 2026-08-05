<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Orders\OrderStatus;
use App\Filament\Actions\OrderTransitionActions;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: paid orders ready to pick and ship.
 *
 * This is the warehouse's screen, so it deliberately shows no prices and no
 * credit data — just what to pick, for whom, and from where.
 */
class OrdersReadyToPick extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canPickAndShip() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Siap dipicking')
            ->emptyStateHeading('Tidak ada order siap dipicking')
            ->query(
                Order::query()
                    ->where('status', OrderStatus::Paid)
                    ->with(['company', 'warehouse', 'lines'])
                    ->orderBy('paid_at')
            )
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable(),
                TextColumn::make('company.nama')->label('Pelanggan')->searchable(),
                TextColumn::make('warehouse.nama')->label('Gudang'),
                TextColumn::make('lines_count')->label('Baris')->counts('lines'),
                TextColumn::make('paid_at')->label('Lunas')->since()->sortable(),
            ])
            ->recordActions([
                // Print first, then ship — the order a packer actually
                // works in.
                OrderTransitionActions::suratJalan(),
                OrderTransitionActions::kirim(),
            ]);
    }
}
