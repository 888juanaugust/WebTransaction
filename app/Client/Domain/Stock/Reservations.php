<?php

declare(strict_types=1);

namespace App\Client\Domain\Stock;

use App\Client\Models\StockReservation;
use App\Domain\Inventory\GroupItems;
use App\Domain\Pengaturan\BusinessRule;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Shared\Format;
use App\Models\Approval\ApprovalRequest;
use App\Models\GeneralLedger\Posting;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\DeliveryLine;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The stock reservations ledger. An order approved under the Sales Order
 * Approval rule holds its goods in their warehouses from that moment; a
 * delivery pulled from the order consumes the hold, a rejection or an edit
 * that reopens the approval releases it, and a delivery that is unposted
 * reopens it. Goods held for an order never leave for another: a delivery
 * that would take them is refused. Nothing expires.
 */
final class Reservations
{
    /** Reserve or release so the ledger follows the order's approval; called from the approval type's `changed` hook. */
    public function sync(SalesOrder $order, ?ApprovalRequest $request): void
    {
        if (! BusinessRule::SalesOrderApproval->isOn()) {
            return; // approved on entry: nothing is held
        }
        $approved = $request?->status === ApprovalRequest::APPROVED;
        $holds = $this->heldByOrder($order) !== [];
        if ($approved && ! $holds) {
            $this->reserve($order);
        } elseif (! $approved && $holds) {
            $this->release($order, $request?->status === ApprovalRequest::REJECTED ? 'rejected' : 'reopened_approval');
        }
    }

    /**
     * Hold every line's goods in its warehouse. Runs inside the caller's
     * transaction; the item_costs row is locked so two approvals never share
     * the same free stock. Idempotent: an order that already holds rows is left alone.
     *
     * @throws InsufficientStockException
     */
    public function reserve(SalesOrder $order): void
    {
        if ($this->heldByOrder($order) !== []) {
            return;
        }
        $pieces = [];
        foreach ($order->lines()->with('item')->get() as $line) {
            $warehouseId = $this->warehouseFor($line);
            foreach (GroupItems::explode($line->item, (string) $line->base_quantity) as $piece) {
                $pieces[] = ['line' => $line, 'item' => $piece['item'], 'warehouse' => $warehouseId, 'quantity' => $piece['base_quantity']];
            }
        }
        usort($pieces, fn (array $a, array $b) => [$a['item']->id, $a['warehouse'], $a['line']->id] <=> [$b['item']->id, $b['warehouse'], $b['line']->id]);

        foreach ($pieces as $piece) {
            $onHand = $this->lockOnHand($piece['item']->id, $piece['warehouse']);
            $available = BigDecimal::of($onHand)->minus($this->heldSum($piece['item']->id, $piece['warehouse']));
            $wanted = BigDecimal::of($piece['quantity']);
            if ($available->isLessThan($wanted)) {
                throw new InsufficientStockException($piece['item'], Warehouse::query()->findOrFail($piece['warehouse']), self::scale($wanted->minus($available)), self::scale(BigDecimal::max($available, BigDecimal::zero())));
            }
            $this->append($order, $piece['line'], $piece['item']->id, $piece['warehouse'], StockReservation::HELD, $wanted, null);
        }
    }

    /** Let go of everything the order still holds. */
    public function release(SalesOrder $order, string $reason): void
    {
        foreach ($this->heldByOrder($order) as $hold) {
            $this->append($order, $hold['line'], $hold['item'], $hold['warehouse'], StockReservation::RELEASED, BigDecimal::of($hold['quantity'])->negated(), $reason);
        }
    }

    /**
     * The posting writer, inside every delivery's posting transaction: the
     * goods leaving consume what their order lines held; a delivery from
     * another warehouse releases the hold instead; then nothing held for
     * other orders may have left.
     */
    public function consume(Posting $posting, PostingBuilder $builder): void
    {
        if ($posting->document_type !== 'delivery') {
            return;
        }
        $this->reopenSuperseded($posting);
        $touched = [];
        foreach ($builder->stockMovements() as $movement) {
            if (($movement['direction'] ?? null) !== StockMovement::OUT || ($movement['source_line_type'] ?? null) !== 'delivery_line') {
                continue;
            }
            $itemId = (int) $movement['item_id'];
            $warehouseId = (int) $movement['warehouse_id'];
            $touched["{$itemId}:{$warehouseId}"] = [$itemId, $warehouseId];
            $deliveryLine = DeliveryLine::query()->find($movement['source_line_id']);
            if ($deliveryLine === null || $deliveryLine->source_line_type !== 'sales_order_line') {
                continue;
            }
            $orderLine = SalesOrderLine::query()->find($deliveryLine->source_line_id);
            if ($orderLine === null) {
                continue;
            }
            $order = $orderLine->salesOrder;
            $remaining = BigDecimal::of((string) $movement['base_quantity']);
            foreach ($this->heldByLine($orderLine->id, $itemId) as $hold) {
                if ($remaining->isLessThanOrEqualTo(0)) {
                    break;
                }
                $take = BigDecimal::min($remaining, BigDecimal::of($hold['quantity']));
                $same = (int) $hold['warehouse'] === $warehouseId;
                $this->append($order, $orderLine, $itemId, (int) $hold['warehouse'], $same ? StockReservation::CONSUMED : StockReservation::RELEASED, $take->negated(), $same ? null : 'delivered_elsewhere', $posting->id);
                $remaining = $remaining->minus($take);
            }
        }
        foreach ($touched as [$itemId, $warehouseId]) {
            $this->assertNothingHeldLeft($itemId, $warehouseId);
        }
    }

    /** The unposter: what a superseded delivery consumed or released comes back. */
    public function unpost(Posting $posting): void
    {
        if ($posting->document_type !== 'delivery') {
            return;
        }
        $this->reopen(StockReservation::query()->where('posting_id', $posting->id)->where('quantity', '<', 0)->orderBy('id')->get());
    }

    /** What every order holds in a warehouse, in base units. */
    public function heldSum(int $itemId, int $warehouseId): string
    {
        return self::scale(BigDecimal::of((string) StockReservation::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->sum('quantity')));
    }

    /** On hand less what orders hold, over every active warehouse: what a buyer may count on, wherever it sits. */
    public function availableAnywhere(int $itemId): string
    {
        $warehouses = DamagedGoods::saleable(Warehouse::query())->pluck('id'); // damaged goods are never for sale
        $onHand = (string) (ItemCost::query()->where('item_id', $itemId)->whereIn('warehouse_id', $warehouses)->sum('qty_on_hand') ?: '0');
        $held = (string) (StockReservation::query()->where('item_id', $itemId)->whereIn('warehouse_id', $warehouses)->sum('quantity') ?: '0');

        return self::scale(BigDecimal::of($onHand)->minus($held));
    }

    /** On hand less what orders hold. */
    public function available(int $itemId, int $warehouseId): string
    {
        $onHand = (string) (ItemCost::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->value('qty_on_hand') ?? '0');

        return self::scale(BigDecimal::of($onHand)->minus($this->heldSum($itemId, $warehouseId)));
    }

    /** @return list<array{line: ?SalesOrderLine, item: int, warehouse: int, quantity: string}> the holds an order still has, one per line, item and warehouse */
    public function heldByOrder(SalesOrder $order): array
    {
        return $this->holds(StockReservation::query()->where('sales_order_id', $order->id));
    }

    /** @return list<array{line: ?SalesOrderLine, item: int, warehouse: int, quantity: string}> */
    private function heldByLine(int $lineId, int $itemId): array
    {
        return $this->holds(StockReservation::query()->where('sales_order_line_id', $lineId)->where('item_id', $itemId));
    }

    /** @return list<array{line: ?SalesOrderLine, item: int, warehouse: int, quantity: string}> */
    private function holds($query): array
    {
        $rows = $query->selectRaw('sales_order_line_id, item_id, warehouse_id, SUM(quantity) AS quantity')
            ->groupBy('sales_order_line_id', 'item_id', 'warehouse_id')
            ->orderBy('sales_order_line_id')->orderBy('item_id')->orderBy('warehouse_id')
            ->get();
        $holds = [];
        foreach ($rows as $row) {
            if (BigDecimal::of((string) $row->quantity)->isLessThanOrEqualTo(0)) {
                continue;
            }
            $holds[] = ['line' => $row->sales_order_line_id ? SalesOrderLine::query()->find($row->sales_order_line_id) : null, 'item' => (int) $row->item_id, 'warehouse' => (int) $row->warehouse_id, 'quantity' => self::scale(BigDecimal::of((string) $row->quantity))];
        }

        return $holds;
    }

    /** A superseded posting of the same key (the delivery re-posted after an edit) gives back what it consumed before the new posting consumes. */
    private function reopenSuperseded(Posting $posting): void
    {
        $previous = Posting::query()->where('posting_key', $posting->posting_key)->whereNotNull('superseded_at')->pluck('id');
        if ($previous->isEmpty()) {
            return;
        }
        $this->reopen(StockReservation::query()->whereIn('posting_id', $previous)->where('quantity', '<', 0)->orderBy('id')->get());
    }

    /** @param  iterable<StockReservation>  $rows  negative rows to reverse, each once, unless the order no longer holds anything (rejected since) */
    private function reopen(iterable $rows): void
    {
        foreach ($rows as $row) {
            if (StockReservation::query()->where('reverses_id', $row->id)->exists()) {
                continue;
            }
            $order = SalesOrder::query()->find($row->sales_order_id);
            if ($order === null || $order->approval_status !== SalesOrder::APPROVED) {
                continue;
            }
            $this->append($order, $row->sales_order_line_id ? SalesOrderLine::query()->find($row->sales_order_line_id) : null, (int) $row->item_id, (int) $row->warehouse_id, StockReservation::REOPENED, BigDecimal::of((string) $row->quantity)->negated(), $row->reason, null, $row->id);
        }
    }

    private function assertNothingHeldLeft(int $itemId, int $warehouseId): void
    {
        $available = BigDecimal::of($this->available($itemId, $warehouseId));
        if ($available->isNegative()) {
            $item = Item::query()->find($itemId);
            $warehouse = Warehouse::query()->find($warehouseId);
            throw new RuntimeException(__('Other orders hold :short of :item in :warehouse; the goods cannot leave for this delivery.', [
                'short' => Format::quantity(self::scale($available->negated())), 'item' => $item?->name ?? $itemId, 'warehouse' => $warehouse?->name ?? $warehouseId,
            ]));
        }
    }

    private function append(SalesOrder $order, ?SalesOrderLine $line, int $itemId, int $warehouseId, string $kind, BigDecimal $quantity, ?string $reason, ?int $postingId = null, ?int $reversesId = null): void
    {
        StockReservation::query()->create([
            'sales_order_id' => $order->id,
            'sales_order_line_id' => $line?->id,
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'kind' => $kind,
            'quantity' => self::scale($quantity),
            'reason' => $reason,
            'posting_id' => $postingId,
            'reverses_id' => $reversesId,
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    /** The line's warehouse, else the customer's default, else the company's. */
    private function warehouseFor(SalesOrderLine $line): int
    {
        $id = $line->warehouse_id ?? $line->salesOrder->customer?->default_warehouse_id ?? Warehouse::default()?->id;

        return $id !== null ? (int) $id : throw new RuntimeException(__('No warehouse to reserve :item in.', ['item' => $line->item->name]));
    }

    /** The cached on-hand quantity of the pair, locked for the rest of the transaction. */
    private function lockOnHand(int $itemId, int $warehouseId): string
    {
        DB::table('item_costs')->insertOrIgnore(['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'qty_on_hand' => 0, 'avg_cost' => 0, 'total_value' => 0, 'updated_at' => now()]);

        return (string) (DB::table('item_costs')->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->lockForUpdate()->value('qty_on_hand') ?? '0');
    }

    private static function scale(BigDecimal $value): string
    {
        return (string) $value->toScale(4, RoundingMode::HalfUp);
    }
}
