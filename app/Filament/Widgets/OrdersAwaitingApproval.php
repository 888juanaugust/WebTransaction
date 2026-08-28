<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Access\Role;
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

    /**
     * The approval queue belongs to whoever can empty it. Sales still see it
     * — their submitted orders wait here and "has marketing looked at mine"
     * is a question they ask hourly — but the buttons inside only render for
     * the approval seat.
     */
    public static function canView(): bool
    {
        $role = auth()->user()?->role();

        return ($role?->canApproveOrders() || $role?->canCreateOrders()) ?? false;
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
                    /*
                     * A marketing sees their own customers' queue, because
                     * theirs is the only queue they can act on — an order for
                     * a colleague's customer is noise they cannot resolve.
                     * Everyone else — the Owner clearing a backlog, a
                     * salesperson checking on their submission — sees the
                     * region's whole list.
                     */
                    ->when(
                        auth()->user()?->role() === Role::Marketing,
                        fn ($q) => $q->whereHas(
                            'company',
                            fn ($c) => $c->where('marketing_user_id', auth()->id()),
                        ),
                    )
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
                // Marketing's broom: an order the customer walked away from
                // is erased right where it clutters, with the audit log as
                // its remaining trace.
                OrderTransitionActions::hapus(),
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
