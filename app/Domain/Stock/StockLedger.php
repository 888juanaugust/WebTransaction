<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Stock is an append-only ledger.
 *
 * There is no `UPDATE products SET stock = stock - n` anywhere in this
 * codebase. Every change inserts a stock_movements row; stock_levels is a
 * cache updated inside the same transaction, and reconcile() proves it can be
 * rebuilt by summing the ledger.
 *
 * Quantities here are always base units.
 *
 * Reservations are not movements. Stock is *reserved* at confirmed — fenced
 * off so a second order can't promise it — and only *decremented* at shipped.
 */
class StockLedger
{
    /**
     * Record a movement and update the cached level in one transaction.
     *
     * Callers already inside a transaction get this joined to theirs, which is
     * what the order flows want.
     */
    public function record(
        string $sku,
        int $warehouseId,
        int $qtySigned,
        MovementReason $reason,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?User $actor = null,
        ?string $catatan = null,
    ): StockMovement {
        if ($qtySigned === 0) {
            throw new LogicException('A stock movement of zero is not a movement.');
        }

        return DB::transaction(function () use (
            $sku, $warehouseId, $qtySigned, $reason, $referenceType, $referenceId, $actor, $catatan
        ) {
            $level = $this->lockLevel($sku, $warehouseId);

            $movement = StockMovement::create([
                'sku' => $sku,
                'warehouse_id' => $warehouseId,
                'qty_signed' => $qtySigned,
                'reason' => $reason->value,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actor?->id,
                'catatan' => $catatan,
            ]);

            $level->qty_on_hand += $qtySigned;
            $level->save();

            return $movement;
        });
    }

    /**
     * Reserve stock for every line of a confirmed order.
     *
     * Takes SELECT ... FOR UPDATE on each stock row inside the confirming
     * transaction, so two staff confirming against the last carton serialise
     * and the second one fails rather than overselling.
     *
     * @return list<StockReservation>
     *
     * @throws InsufficientStockException
     */
    public function reserveForOrder(Order $order, ?User $actor = null): array
    {
        return DB::transaction(function () use ($order, $actor) {
            $reservations = [];

            // Deterministic order to keep concurrent confirmations from
            // deadlocking against each other on the same pair of SKUs.
            $lines = $order->lines()->reorder()->orderBy('sku')->orderBy('id')->get();

            foreach ($lines as $line) {
                $reservations[] = $this->reserveLine($order, $line, $actor);
            }

            return $reservations;
        });
    }

    /** @throws InsufficientStockException */
    public function reserveLine(Order $order, OrderLine $line, ?User $actor = null): StockReservation
    {
        return DB::transaction(function () use ($order, $line, $actor) {
            $level = $this->lockLevel($line->sku, $order->warehouse_id);

            $available = $level->qty_on_hand - $level->qty_reserved;

            if ($available < $line->qty_base) {
                throw new InsufficientStockException(
                    $line->sku,
                    $order->warehouse_id,
                    $line->qty_base,
                    $available,
                );
            }

            $level->qty_reserved += $line->qty_base;
            $level->save();

            return StockReservation::create([
                'order_id' => $order->id,
                'order_line_id' => $line->id,
                'sku' => $line->sku,
                'warehouse_id' => $order->warehouse_id,
                'qty_base' => $line->qty_base,
                'status' => StockReservation::STATUS_HELD,
            ]);
        });
    }

    /**
     * Give held stock back — a rejected order, or the scheduled sweep of stale
     * unpaid ones. Nothing is written to the movement ledger: the stock never
     * left, it was only fenced.
     *
     * Idempotent: reservations already resolved are skipped. Who released it
     * is recorded on the order_events row, not here.
     */
    public function releaseForOrder(Order $order, string $resolutionReason): int
    {
        return DB::transaction(function () use ($order, $resolutionReason) {
            $held = $order->reservations()
                ->where('status', StockReservation::STATUS_HELD)
                ->lockForUpdate()
                ->get();

            foreach ($held as $reservation) {
                $level = $this->lockLevel($reservation->sku, $reservation->warehouse_id);
                $level->qty_reserved = max(0, $level->qty_reserved - $reservation->qty_base);
                $level->save();

                $reservation->forceFill([
                    'status' => StockReservation::STATUS_RELEASED,
                    'resolved_at' => now(),
                    'resolution_reason' => $resolutionReason,
                ])->save();
            }

            return $held->count();
        });
    }

    /**
     * Ship the order: turn each held reservation into an actual decrement.
     *
     * This is the only place stock leaves the warehouse for a sale, and it
     * happens at `shipped`, never at `confirmed` or `paid`.
     *
     * @return list<StockMovement>
     */
    public function shipOrder(Order $order, ?User $actor = null): array
    {
        return DB::transaction(function () use ($order, $actor) {
            $movements = [];

            $held = $order->reservations()
                ->where('status', StockReservation::STATUS_HELD)
                ->orderBy('sku')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($held->isEmpty()) {
                throw new LogicException(
                    "Order {$order->nomor} has no held reservations to ship."
                );
            }

            foreach ($held as $reservation) {
                $level = $this->lockLevel($reservation->sku, $reservation->warehouse_id);

                $movements[] = StockMovement::create([
                    'sku' => $reservation->sku,
                    'warehouse_id' => $reservation->warehouse_id,
                    'qty_signed' => -$reservation->qty_base,
                    'reason' => MovementReason::Pengiriman->value,
                    'reference_type' => Order::class,
                    'reference_id' => (string) $order->id,
                    'actor_id' => $actor?->id,
                ]);

                // The reservation becomes the movement: on-hand drops, and the
                // reserved fence comes down at the same moment.
                $level->qty_on_hand -= $reservation->qty_base;
                $level->qty_reserved = max(0, $level->qty_reserved - $reservation->qty_base);
                $level->save();

                $reservation->forceFill([
                    'status' => StockReservation::STATUS_CONSUMED,
                    'resolved_at' => now(),
                    'resolution_reason' => 'shipped',
                ])->save();
            }

            return $movements;
        });
    }

    public function available(string $sku, int $warehouseId): int
    {
        $level = StockLevel::query()
            ->where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->first();

        return $level ? $level->qtyAvailable() : 0;
    }

    /**
     * Sum the ledger. The cached qty_on_hand must always equal this.
     */
    public function onHandFromLedger(string $sku, int $warehouseId): int
    {
        return (int) StockMovement::query()
            ->where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->sum('qty_signed');
    }

    /**
     * Rebuild the cache from the ledger.
     *
     * The invariant is that this changes nothing. Run it as a scheduled audit;
     * if it ever reports a drift, something wrote stock outside this class.
     *
     * @return list<array{sku: string, warehouse_id: int, cached: int, ledger: int}>
     */
    public function reconcile(): array
    {
        $drift = [];

        StockLevel::query()->orderBy('id')->chunkById(500, function ($levels) use (&$drift) {
            foreach ($levels as $level) {
                $ledger = $this->onHandFromLedger($level->sku, $level->warehouse_id);

                if ($ledger !== $level->qty_on_hand) {
                    $drift[] = [
                        'sku' => $level->sku,
                        'warehouse_id' => $level->warehouse_id,
                        'cached' => $level->qty_on_hand,
                        'ledger' => $ledger,
                    ];
                }
            }
        });

        return $drift;
    }

    /**
     * SELECT ... FOR UPDATE the level row, creating it if this SKU has never
     * been stocked in this warehouse before.
     */
    private function lockLevel(string $sku, int $warehouseId): StockLevel
    {
        $level = StockLevel::query()
            ->where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($level !== null) {
            return $level;
        }

        // Two concurrent first-touches race here; the unique index decides,
        // and the loser re-reads the winner's row under the same lock.
        StockLevel::query()->insertOrIgnore([
            'sku' => $sku,
            'warehouse_id' => $warehouseId,
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StockLevel::query()
            ->where('sku', $sku)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
