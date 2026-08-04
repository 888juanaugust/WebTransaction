<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use App\Domain\Money;
use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Recent orders — the top of the buyer portal priority list.
 *
 * B2B buyers reorder the same 15–20 SKUs forever, so this is the screen they
 * actually came for. One-click reorder is not wired yet: self-service ordering
 * is a later phase, and a button that silently does nothing is worse than no
 * button. The history it needs is here and correct in the meantime.
 */
class OrderTerakhir extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $companyId = auth('customer')->user()?->company_id;

        return $table
            ->heading('Order terakhir')
            ->emptyStateHeading('Belum ada order')
            ->emptyStateDescription('Order yang dibuat tim kami untuk Anda akan muncul di sini.')
            ->query(
                Order::query()
                    // Scoped to the buyer's own company. A portal query that
                    // forgets this leaks another customer's trading history.
                    ->where('company_id', $companyId)
                    ->with('warehouse')
                    ->latest('created_at')
            )
            ->columns([
                TextColumn::make('nomor')->label('Nomor')->searchable(),

                TextColumn::make('created_at')->label('Tanggal')->date('d/m/Y')->sortable(),

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

                TextColumn::make('lines_count')->label('Baris')->counts('lines'),

                TextColumn::make('total_rupiah')
                    ->label('Total')
                    // Unconfirmed orders have no price snapshot yet; "Rp 0"
                    // would read as free rather than not-yet-priced.
                    ->formatStateUsing(fn (?int $state, Order $record) => $record->confirmed_at === null
                        ? '— belum dihitung —'
                        : Money::format((int) $state)),
            ])
            ->paginated([5, 10, 25]);
    }
}
