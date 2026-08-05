<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Credit\CreditChecker;
use App\Domain\Money;
use App\Domain\Orders\OrderStatus;
use App\Domain\Stock\StockLedger;
use App\Filament\Actions\OrderTransitionActions;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Queue: orders awaiting approval, with credit and stock shown inline.
 *
 * The point of showing both here is that approving is a judgement call, and
 * the two things that make it a bad one — no credit left, no stock on the
 * shelf — should not require opening another screen.
 */
class OrdersAwaitingApproval extends TableWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role()->canSeePrices() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Order menunggu persetujuan')
            ->emptyStateHeading('Tidak ada order menunggu persetujuan')
            ->query(
                Order::query()
                    ->where('status', OrderStatus::Submitted)
                    ->with(['company', 'lines', 'warehouse'])
                    ->orderBy('submitted_at')
            )
            ->columns([
                TextColumn::make('nomor')
                    ->label('Nomor')
                    ->searchable(),

                TextColumn::make('company.nama')
                    ->label('Pelanggan')
                    ->searchable(),

                TextColumn::make('submitted_at')
                    ->label('Diajukan')
                    ->since()
                    ->sortable(),

                TextColumn::make('lines_count')
                    ->label('Baris')
                    ->counts('lines'),

                TextColumn::make('kredit')
                    ->label('Sisa kredit')
                    ->state(fn (Order $record) => Money::format(
                        app(CreditChecker::class)->available($record->company)
                    ))
                    // Blue means "nothing wrong here"; red is reserved for the
                    // two conditions that should stop an approval.
                    ->color(fn (Order $record) => app(CreditChecker::class)->available($record->company) > 0
                        ? 'primary'
                        : 'danger'),

                TextColumn::make('stok')
                    ->label('Stok')
                    ->state(fn (Order $record) => $this->stockSummary($record))
                    ->color(fn (Order $record) => str_contains($this->stockSummary($record), 'kurang')
                        ? 'danger'
                        : 'primary')
                    ->wrap(),
            ])
            ->recordActions([
                // The same objects the order list and detail page use, so
                // approving from the queue and approving from the order
                // itself cannot drift apart.
                OrderTransitionActions::setujui(),
                OrderTransitionActions::tolak(),
            ]);
    }

    /** "Cukup" or a list of the SKUs that are short. */
    private function stockSummary(Order $order): string
    {
        $ledger = app(StockLedger::class);
        $short = [];

        foreach ($order->lines as $line) {
            $available = $ledger->available($line->sku, $order->warehouse_id);

            if ($available < $line->qty_base) {
                $short[] = "{$line->sku} (butuh {$line->qty_base}, ada {$available})";
            }
        }

        return $short === [] ? 'Cukup' : 'Stok kurang: '.implode(', ', $short);
    }
}
