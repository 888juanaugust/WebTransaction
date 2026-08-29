<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
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
    public function __construct(
        private readonly InventoryValuation $valuation = new InventoryValuation,
    ) {}

    /**
     * Record a movement and update the cached level in one transaction.
     *
     * Callers already inside a transaction get this joined to theirs, which is
     * what the order flows want.
     *
     * `$valueRupiah` is the value the goods carry *into* stock, positive, and
     * is only meaningful for an inbound movement — a goods receipt knows what
     * it paid. Outbound movements do not take a value: what they are worth is
     * decided by the running average, not by whoever is writing the movement,
     * which is the whole reason cost cannot be argued with after the fact.
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
        ?int $valueRupiah = null,
    ): StockMovement {
        if ($qtySigned === 0) {
            throw new LogicException('A stock movement of zero is not a movement.');
        }

        if ($valueRupiah !== null && $qtySigned < 0) {
            throw new LogicException(
                'An outbound movement is valued by the running average, not by its caller.'
            );
        }

        return DB::transaction(function () use (
            $sku, $warehouseId, $qtySigned, $reason, $referenceType, $referenceId,
            $actor, $catatan, $valueRupiah
        ) {
            $level = $this->lockLevel($sku, $warehouseId);

            [$unitCost, $value] = $this->valueOf($sku, $qtySigned, $valueRupiah);

            $movement = StockMovement::create([
                'sku' => $sku,
                'warehouse_id' => $warehouseId,
                'qty_signed' => $qtySigned,
                'unit_cost_rupiah' => $unitCost,
                'value_rupiah' => $value,
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
     * Move the running average for one movement, and report what to stamp on it.
     *
     * @return array{0: ?int, 1: ?int} unit cost and signed value
     */
    private function valueOf(string $sku, int $qtySigned, ?int $valueRupiah): array
    {
        if ($qtySigned > 0) {
            // Inbound with no stated value — a correction or a transfer in.
            // It enters at the average it already carries, which keeps the
            // total value unchanged for a transfer and is the only defensible
            // figure for an adjustment nobody paid for.
            $value = $valueRupiah ?? ($this->valuation->unitCost($sku) * $qtySigned);

            $cost = $this->valuation->applyReceipt($sku, $qtySigned, $value);

            return [$cost->unitCost(), $value];
        }

        $issued = $this->valuation->applyIssue($sku, -$qtySigned);

        return $issued->valued
            ? [$issued->unitCost, -$issued->value]
            : [null, null];
    }

    /**
     * Move stock between warehouses, both legs in one transaction.
     *
     * A transfer must be **value-neutral**. `product_costs` is keyed by SKU
     * and not by warehouse, so moving goods between two of our own buildings
     * changes nothing about what the inventory is worth — and the ledger has
     * to reflect that exactly, not approximately.
     *
     * Calling record() twice would not. The outbound leg takes value out at
     * the running average; the inbound leg, with no value stated, would come
     * back in at the average *recomputed after* that removal. Integer rounding
     * makes those two figures differ by a rupiah on awkward numbers, and a
     * rupiah destroyed by walking a carton across the yard is a rupiah nobody
     * can ever explain. So the value that left is carried across and put back
     * verbatim.
     *
     * Unvalued stock — the opening balances that predate costing — stays
     * unvalued on both legs. Writing a valued zero on the way in would leave
     * unvaluedQuantity() reporting a shortfall forever on a shelf that
     * balances.
     *
     * @return array{0: StockMovement, 1: StockMovement} out, in
     */
    public function transfer(
        string $sku,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $qtyBase,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?User $actor = null,
        ?string $catatan = null,
    ): array {
        if ($qtyBase <= 0) {
            throw new LogicException('A transfer moves a positive quantity.');
        }

        if ($fromWarehouseId === $toWarehouseId) {
            throw new LogicException('A transfer needs two different warehouses.');
        }

        return DB::transaction(function () use (
            $sku, $fromWarehouseId, $toWarehouseId, $qtyBase, $referenceType,
            $referenceId, $actor, $catatan
        ) {
            /*
             * Both rows locked up front, lowest warehouse id first. Two
             * transfers of the same SKU in opposite directions would otherwise
             * each hold the row the other wants — the same deadlock the order
             * confirmation avoids by sorting its lines.
             */
            $ids = [$fromWarehouseId, $toWarehouseId];
            sort($ids);

            $levels = [];

            foreach ($ids as $id) {
                $levels[$id] = $this->lockLevel($sku, $id);
            }

            $from = $levels[$fromWarehouseId];
            $to = $levels[$toWarehouseId];

            if ($from->qty_on_hand - $from->qty_reserved < $qtyBase) {
                throw new InsufficientStockException(
                    sku: $sku,
                    warehouseId: $fromWarehouseId,
                    requested: $qtyBase,
                    available: max(0, $from->qty_on_hand - $from->qty_reserved),
                );
            }

            $issued = $this->valuation->applyIssue($sku, $qtyBase);

            $out = StockMovement::create([
                'sku' => $sku,
                'warehouse_id' => $fromWarehouseId,
                'qty_signed' => -$qtyBase,
                'unit_cost_rupiah' => $issued->valued ? $issued->unitCost : null,
                'value_rupiah' => $issued->valued ? -$issued->value : null,
                'reason' => MovementReason::TransferKeluar->value,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actor?->id,
                'catatan' => $catatan,
            ]);

            // Exactly what left, back in. Not the average recomputed since.
            if ($issued->valued) {
                $this->valuation->applyReceipt($sku, $qtyBase, $issued->value);
            }

            $in = StockMovement::create([
                'sku' => $sku,
                'warehouse_id' => $toWarehouseId,
                'qty_signed' => $qtyBase,
                'unit_cost_rupiah' => $issued->valued ? $issued->unitCost : null,
                'value_rupiah' => $issued->valued ? $issued->value : null,
                'reason' => MovementReason::TransferMasuk->value,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actor?->id,
                'catatan' => $catatan,
            ]);

            $from->qty_on_hand -= $qtyBase;
            $from->save();

            $to->qty_on_hand += $qtyBase;
            $to->save();

            return [$out, $in];
        });
    }

    /**
     * Raise the cost of stock already on the shelf. Nothing moves.
     *
     * A freight or duty invoice arrives after the goods it belongs to and says
     * they cost more than we booked them at. That is a change of value with no
     * change of quantity — the one shape `record()` refuses, and rightly: a
     * zero-quantity *movement* is nonsense, and letting the guard through
     * would mean every caller has to be trusted not to write one by accident.
     *
     * So it gets its own door, and it still writes a row. It would be less
     * code to move the value straight onto `product_costs` and skip the
     * ledger, and it would be wrong twice over. `reconcile()` proves the
     * cached pair by summing the movements; value applied behind its back
     * makes that check report a permanent drift it cannot explain. And a
     * person looking at why this SKU's average jumped last Tuesday would find
     * nothing in its history saying so.
     *
     * `qty_signed = 0` is therefore not a degenerate movement but an accurate
     * one: on this date, for this SKU, the value changed and the quantity did
     * not.
     */
    public function addCost(
        string $sku,
        int $warehouseId,
        int $valueRupiah,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?User $actor = null,
        ?string $catatan = null,
    ): StockMovement {
        if ($valueRupiah <= 0) {
            throw new LogicException('An added cost must be positive.');
        }

        return DB::transaction(function () use (
            $sku, $warehouseId, $valueRupiah, $referenceType, $referenceId, $actor, $catatan
        ) {
            // Locked even though the quantity does not change: the valuation
            // is about to, and a shipment racing this must not read the
            // average from between the two writes.
            $this->lockLevel($sku, $warehouseId);

            $cost = $this->valuation->addCost($sku, $valueRupiah);

            return StockMovement::create([
                'sku' => $sku,
                'warehouse_id' => $warehouseId,
                'qty_signed' => 0,
                'unit_cost_rupiah' => $cost->unitCost(),
                'value_rupiah' => $valueRupiah,
                'reason' => MovementReason::BiayaPerolehan->value,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actor?->id,
                'catatan' => $catatan,
            ]);
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
        return DB::transaction(function () use ($order, $line) {
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
            // Same lock order as reserveForOrder — by SKU — so a release and
            // a confirmation running at the same moment walk the stock rows
            // in one direction instead of meeting in the middle.
            $held = $order->reservations()
                ->where('status', StockReservation::STATUS_HELD)
                ->orderBy('sku')
                ->orderBy('id')
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

                /*
                 * COGS, frozen here.
                 *
                 * The average that applies is the one standing at the moment
                 * the goods leave. A receipt arriving tomorrow moves the
                 * average for everything after it and changes nothing about
                 * this shipment — which is what stops last month's gross margin
                 * from shifting because somebody bought stock today.
                 */
                $issued = $this->valuation->applyIssue($reservation->sku, $reservation->qty_base);

                $movements[] = StockMovement::create([
                    'sku' => $reservation->sku,
                    'warehouse_id' => $reservation->warehouse_id,
                    'qty_signed' => -$reservation->qty_base,
                    'unit_cost_rupiah' => $issued->unitCost,
                    'value_rupiah' => $issued->valued ? -$issued->value : null,
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

        /*
         * The level's region is the warehouse's region, read from the
         * warehouse row rather than from RegionContext. They are the same
         * whenever a request is pinned — a pinned request cannot see another
         * region's warehouse to pass in — but a console command or job walking
         * all regions is unpinned, and deriving from the warehouse keeps the
         * pair from ever disagreeing. insertOrIgnore skips Eloquent events, so
         * the HasRegion stamp does not run here and the column is explicit.
         */
        $regionId = Warehouse::query()
            ->withoutGlobalScope('region')
            ->whereKey($warehouseId)
            ->value('region_id');

        // Two concurrent first-touches race here; the unique index decides,
        // and the loser re-reads the winner's row under the same lock.
        StockLevel::query()->insertOrIgnore([
            'region_id' => $regionId,
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
