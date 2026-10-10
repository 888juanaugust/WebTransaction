<?php

declare(strict_types=1);

namespace App\Client\Domain\Orders;

use App\Client\Domain\Stock\DamagedGoods;
use App\Client\Domain\Stock\Reservations;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\Auditor;
use App\Domain\Inventory\GroupItems;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Pengaturan\BusinessRule;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Posting\Revisions;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemUnit;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;
use App\Models\Settings\DocumentSeries;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Serves an order whose goods sit in several warehouses. The plan is pure:
 * the home warehouse first, then the other warehouses of the customer's
 * branch, then the rest by what they hold of the order's items, each line
 * taken greedily. Executing the plan keeps the home share on the order,
 * makes one sibling order per other warehouse in that warehouse's branch,
 * and approves every piece, all in one transaction. An order whose plan
 * needs a split is refused by the ordinary approve button and approved
 * from Order Approvals, where the plan is shown first.
 */
final class OrderSplitter
{
    private static bool $executing = false;

    public function __construct(
        private readonly Reservations $reservations,
        private readonly DocumentRepository $documents,
        private readonly ApprovalEngine $approvals,
        private readonly NumberGenerator $numbers,
        private readonly Revisions $revisions,
    ) {}

    public function plan(SalesOrder $order): SplitPlan
    {
        $order->loadMissing(['customer', 'lines.item']);
        $priority = $this->priority($order);
        $free = []; // item:warehouse → what is still free in this plan
        $shares = [];
        $shortfalls = [];
        foreach ($order->lines as $line) {
            $need = BigDecimal::of((string) $line->base_quantity);
            $own = $line->warehouse_id ? [(int) $line->warehouse_id] : [];
            foreach (array_values(array_unique([...$own, ...$priority])) as $warehouseId) {
                if ($need->isLessThanOrEqualTo(0)) {
                    break;
                }
                $key = "{$line->item_id}:{$warehouseId}";
                $free[$key] ??= BigDecimal::of($this->freeOf($line->item, $warehouseId));
                $take = BigDecimal::min($need, BigDecimal::max($free[$key], BigDecimal::zero()));
                if ($take->isLessThanOrEqualTo(0)) {
                    continue;
                }
                $shares[$warehouseId][] = ['line' => $line, 'quantity' => self::scale($take)];
                $free[$key] = $free[$key]->minus($take);
                $need = $need->minus($take);
            }
            if ($need->isPositive()) {
                $shortfalls[] = ['line' => $line, 'quantity' => self::scale($need)];
            }
        }
        $ordered = [];
        foreach (array_values(array_unique([...array_map(fn ($l) => (int) $l->warehouse_id, $order->lines->whereNotNull('warehouse_id')->all()), ...$priority])) as $warehouseId) {
            if (isset($shares[$warehouseId])) {
                $ordered[] = ['warehouse' => Warehouse::query()->findOrFail($warehouseId), 'lines' => $shares[$warehouseId]];
            }
        }

        return new SplitPlan($ordered, $shortfalls);
    }

    /** The ordinary approve path refuses an order the plan would split; Order Approvals shows the plan and runs it. */
    public function assertNotScattered(SalesOrder $order): void
    {
        if (self::$executing || ! BusinessRule::SalesOrderApproval->isOn()) {
            return;
        }
        if ($this->plan($order)->needsSplit()) {
            throw new RuntimeException(__('The goods of :number sit in several warehouses. Approve it from Order Approvals, where the split is shown first.', ['number' => $order->number]));
        }
    }

    /**
     * Runs the plan and approves every piece, or nothing.
     *
     * @return list<SalesOrder> the pieces, the parent first
     */
    public function execute(SalesOrder $order, SplitPlan $plan, User $actor): array
    {
        if (! $plan->coversAll()) {
            $first = $plan->shortfalls[0];
            throw new RuntimeException(__('No warehouse can cover :quantity of :item on :number.', ['quantity' => $first['quantity'], 'item' => $first['line']->item->name, 'number' => $order->number]));
        }

        return DB::transaction(function () use ($order, $plan, $actor): array {
            self::$executing = true;
            try {
                $home = $plan->shares[0];
                $homeWarehouse = $home['warehouse'];
                $rehomed = $order->lines->contains(fn (SalesOrderLine $l) => (int) $l->warehouse_id !== $homeWarehouse->id);
                if (! $plan->needsSplit() && ! $rehomed) {
                    $this->approvals->approve($order, $actor);

                    return [$order->fresh()];
                }

                $pieces = [];
                $before = $this->snapshot($order);

                // The parent keeps the home share; a parent whose home ships nothing moves to the first warehouse that does.
                $this->approvals->forget($order);
                $this->keepShare($order, $home['lines'], $homeWarehouse);
                if ($rehomed && $homeWarehouse->branch_id !== null && (int) $homeWarehouse->branch_id !== (int) $order->branch_id) {
                    $order->forceFill(['branch_id' => $homeWarehouse->branch_id, 'number' => $this->number($order, $homeWarehouse, $actor)])->saveQuietly();
                }
                $order->refresh()->refreshTotal();
                $this->revisions->record($order, 'updated', $before, $this->snapshot($order));
                $this->approvals->sync($order);
                $this->approvals->approve($order, $actor);
                $pieces[] = $order->fresh();

                foreach (array_slice($plan->shares, 1) as $share) {
                    $sibling = $this->sibling($order, $share['lines'], $share['warehouse'], $actor);
                    $this->approvals->approve($sibling, $actor);
                    $pieces[] = $sibling->fresh();
                }

                Auditor::log('order_split', $order, $order->number, [
                    'rehomed' => $rehomed,
                    'pieces' => array_map(fn (SalesOrder $p) => ['number' => $p->number, 'branch_id' => $p->branch_id, 'total' => $p->total], $pieces),
                ]);

                return $pieces;
            } finally {
                self::$executing = false;
            }
        });
    }

    /** @return list<int> warehouse ids, the home first, then the branch's others by name, then the rest by what they hold of the order's items */
    private function priority(SalesOrder $order): array
    {
        $home = $this->homeWarehouseId($order);
        $branchId = $order->customer?->branch_id ?? $order->branch_id;
        $warehouses = DamagedGoods::saleable(Warehouse::query())->get(); // never ship from the damaged-goods warehouse
        $local = $warehouses->filter(fn (Warehouse $w) => $branchId !== null && (int) $w->branch_id === (int) $branchId && $w->id !== $home)->sortBy('name');
        $foreign = $warehouses->filter(fn (Warehouse $w) => $w->id !== $home && ! $local->contains('id', $w->id))
            ->sortByDesc(fn (Warehouse $w) => $order->lines->reduce(fn (BigDecimal $sum, SalesOrderLine $l) => $sum->plus(BigDecimal::max(BigDecimal::of($this->freeOf($l->item, $w->id)), BigDecimal::zero())), BigDecimal::zero())->toFloat());

        return array_values(array_filter([$home, ...$local->pluck('id')->map(fn ($id) => (int) $id)->all(), ...$foreign->pluck('id')->map(fn ($id) => (int) $id)->all()]));
    }

    private function homeWarehouseId(SalesOrder $order): ?int
    {
        $id = $order->customer?->default_warehouse_id ?? $order->lines->first()?->warehouse_id ?? Warehouse::default()?->id;

        return $id === null ? null : (int) $id;
    }

    /** What a warehouse can still give of an item: free stock, or for a group item the sets its components can make. */
    private function freeOf(Item $item, int $warehouseId): string
    {
        $pieces = GroupItems::explode($item, '1');
        if (count($pieces) === 1 && $pieces[0]['item']->id === $item->id) {
            return $this->reservations->available($item->id, $warehouseId);
        }
        $sets = null;
        foreach ($pieces as $piece) {
            $per = BigDecimal::of($piece['base_quantity']);
            if ($per->isLessThanOrEqualTo(0)) {
                continue;
            }
            $can = BigDecimal::of($this->reservations->available($piece['item']->id, $warehouseId))->dividedBy($per, 0, RoundingMode::Down);
            $sets = $sets === null ? $can : BigDecimal::min($sets, $can);
        }

        return self::scale($sets ?? BigDecimal::zero());
    }

    /** @param  list<array{line: SalesOrderLine, quantity: string}>  $lines */
    private function keepShare(SalesOrder $order, array $lines, Warehouse $warehouse): void
    {
        $kept = [];
        foreach ($lines as $share) {
            $kept[$share['line']->id] = $share['quantity'];
        }
        foreach ($order->lines()->get() as $line) {
            if (! isset($kept[$line->id])) {
                $line->delete();

                continue;
            }
            $line->forceFill($this->restated($line, $kept[$line->id]) + ['warehouse_id' => $warehouse->id])->saveQuietly();
        }
    }

    /** @param  list<array{line: SalesOrderLine, quantity: string}>  $lines */
    private function sibling(SalesOrder $parent, array $lines, Warehouse $warehouse, User $actor): SalesOrder
    {
        $header = collect($parent->getAttributes())
            ->except(['id', 'number', 'series_id', 'branch_id', 'status', 'is_printed', 'subtotal', 'discount_amount', 'charges_total', 'dpp_total', 'tax_total', 'total',
                'approval_status', 'approved_by', 'approved_at', 'rejection_reason', 'split_parent_id', 'created_at', 'updated_at',
                'fc_subtotal', 'fc_discount_amount', 'fc_charges_total', 'fc_tax_total', 'fc_total'])
            ->all();
        $branchId = $warehouse->branch_id ?? $parent->branch_id;
        $sibling = new SalesOrder($header + ['branch_id' => $branchId, 'split_parent_id' => $parent->id, 'approval_status' => SalesOrder::AWAITING, 'description' => trim(__('Split from :number', ['number' => $parent->number]).' '.($parent->description ?? ''))]);
        $series = $this->numbers->defaultSeries(TransactionType::SalesOrder, $actor) ?? throw new RuntimeException(__('No number series for :type.', ['type' => TransactionType::SalesOrder->getLabel()]));
        $sibling->forceFill(['number' => $this->numbers->next($series, $parent->trans_date, $warehouse->branch?->code ?? $parent->branch?->code), 'series_id' => $series->id])->save();

        foreach ($lines as $i => $share) {
            $attributes = collect($share['line']->getAttributes())->except(['id', 'sales_order_id', 'processed_quantity', 'discount_amount', 'header_discount', 'amount', 'dpp_amount', 'tax_amount'])->all();
            $sibling->lines()->create(array_merge($attributes, ['sort' => $i], $this->restated($share['line'], $share['quantity']), ['warehouse_id' => $warehouse->id]));
        }
        $sibling->refreshTotal();
        $this->documents->created($sibling);

        return $sibling;
    }

    /**
     * A line's share in its own unit when the share is whole cartons, else in
     * base units at the price per base unit.
     *
     * @return array{quantity: string, unit_id: int, base_quantity: string, unit_price: string}
     */
    private function restated(SalesOrderLine $line, string $baseShare): array
    {
        $ratio = $this->ratio($line);
        $share = BigDecimal::of($baseShare);
        $inUnit = $share->dividedBy($ratio, 4, RoundingMode::Down);
        if ($inUnit->multipliedBy($ratio)->isEqualTo($share)) {
            return ['quantity' => self::scale($inUnit), 'unit_id' => (int) $line->unit_id, 'base_quantity' => self::scale($share), 'unit_price' => (string) BigDecimal::of((string) $line->unit_price)->toScale(4)];
        }

        return [
            'quantity' => self::scale($share),
            'unit_id' => (int) $line->item->unit1_id,
            'base_quantity' => self::scale($share),
            'unit_price' => (string) BigDecimal::of((string) $line->unit_price)->dividedBy($ratio, 4, RoundingMode::HalfUp),
        ];
    }

    private function ratio(SalesOrderLine $line): BigDecimal
    {
        if ((int) $line->unit_id === (int) $line->item->unit1_id) {
            return BigDecimal::one();
        }
        $ratio = ItemUnit::query()->where('item_id', $line->item_id)->where('unit_id', $line->unit_id)->value('ratio');

        return $ratio ? BigDecimal::of((string) $ratio) : BigDecimal::one();
    }

    private function number(SalesOrder $order, Warehouse $warehouse, User $actor): string
    {
        $series = $order->series_id ? DocumentSeries::query()->find($order->series_id) : null;
        $series ??= $this->numbers->defaultSeries(TransactionType::SalesOrder, $actor) ?? throw new RuntimeException(__('No number series for :type.', ['type' => TransactionType::SalesOrder->getLabel()]));

        return $this->numbers->next($series, $order->trans_date, $warehouse->branch?->code);
    }

    /** @return array{header: array<string, mixed>, lines: list<array<string, mixed>>} */
    private function snapshot(SalesOrder $order): array
    {
        return [
            'header' => collect($order->getAttributes())->except(['updated_at', 'created_at'])->all(),
            'lines' => $order->lines()->get()->map(fn ($l) => $l->getAttributes())->all(),
        ];
    }

    private static function scale(BigDecimal $value): string
    {
        return (string) $value->toScale(4, RoundingMode::HalfUp);
    }
}
