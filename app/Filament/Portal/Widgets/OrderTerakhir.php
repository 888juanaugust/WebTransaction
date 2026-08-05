<?php

declare(strict_types=1);

namespace App\Filament\Portal\Widgets;

use App\Domain\Orders\OrderStatus;
use App\Filament\Portal\Actions\PesanUlangAction;
use App\Filament\Portal\Resources\Orders\OrderResource;
use App\Filament\Portal\Support\PortalLabels;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Recent orders, with reorder on each one — the top of the buyer portal
 * priority list and, by the spec's estimate, 80% of what buyers come here to
 * do.
 *
 * It sits on the landing screen rather than behind a menu because a restocking
 * buyer should not have to navigate anywhere: log in, adjust two quantities,
 * submit.
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
                    ->state(fn (Order $record) => PortalLabels::orderTotal($record)),
            ])
            ->recordActions([
                PesanUlangAction::make(),
                ViewAction::make()
                    ->label('Lihat')
                    ->url(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated([5, 10, 25]);
    }
}
