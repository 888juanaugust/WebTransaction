<?php

declare(strict_types=1);

namespace App\Client\Domain\Stock;

use App\Domain\Reports\Period;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesInvoiceLine;
use App\Models\Sales\SalesReturnLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which products are bought most, least and never, which stock is oldest,
 * what comes back, how fast stock turns. Every view reads the ledgers
 * (invoice lines, return lines, stock movements, item costs) over the same
 * filters; nothing is cached. Cost columns are the caller's to hide.
 */
final class ProductAnalytics
{
    public const VIEWS = ['most_sold', 'least_taken', 'never_sold', 'oldest_stock', 'most_returned', 'turnover'];

    public function __construct(private readonly StockAge $age) {}

    /** @return array<string, string> */
    public static function viewLabels(): array
    {
        return [
            'most_sold' => __('Most sold'),
            'least_taken' => __('Least taken'),
            'never_sold' => __('Never sold'),
            'oldest_stock' => __('Oldest stock'),
            'most_returned' => __('Most returned'),
            'turnover' => __('Turnover'),
        ];
    }

    /**
     * @param  array{view?: string, period?: Period, warehouse_id?: int|null, category_id?: int|null, brand_id?: int|null, rank?: string, days?: int}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters): Collection
    {
        $period = $filters['period'] ?? Period::month((int) today()->year, (int) today()->month);

        return match ($filters['view'] ?? 'most_sold') {
            'least_taken' => $this->leastTaken($filters, (int) ($filters['days'] ?? 90)),
            'never_sold' => $this->neverSold($filters),
            'oldest_stock' => $this->oldestStock($filters),
            'most_returned' => $this->mostReturned($filters, $period),
            'turnover' => $this->turnover($filters, $period),
            default => $this->mostSold($filters, $period, (string) ($filters['rank'] ?? 'quantity')),
        };
    }

    /** Sold items ranked by quantity, by net value or by the number of distinct customers. */
    public function mostSold(array $filters, Period $period, string $rank = 'quantity'): Collection
    {
        $rows = $this->invoiceLines($filters, $period)
            ->selectRaw('sales_invoice_lines.item_id, items.number, items.name, COUNT(DISTINCT sales_invoices.id) AS invoices, COUNT(DISTINCT sales_invoices.customer_id) AS customers, SUM(sales_invoice_lines.base_quantity) AS quantity, SUM(sales_invoice_lines.amount - sales_invoice_lines.header_discount) AS amount')
            ->groupBy('sales_invoice_lines.item_id', 'items.number', 'items.name')
            ->get();
        // The cost of what left: the OUT movements the deliveries and direct invoices posted in the period, per item.
        $cost = StockMovement::query()->active()->where('direction', StockMovement::OUT)
            ->whereIn('source_line_type', ['delivery_line', 'sales_invoice_line'])
            ->whereBetween('trans_date', [$period->fromDate(), $period->untilDate()])
            ->whereIn('warehouse_id', $this->warehouseIds($filters))
            ->selectRaw('item_id, SUM(total_cost) AS cost')->groupBy('item_id')->get()->keyBy('item_id');
        $sort = match ($rank) {
            'value' => 'amount', 'customers' => 'customers', default => 'quantity'
        };

        return $rows->map(fn ($r) => [
            'key' => $r->item_id, 'item_id' => $r->item_id, 'number' => $r->number, 'name' => $r->name,
            'invoices' => (int) $r->invoices, 'customers' => (int) $r->customers,
            'quantity' => (string) BigDecimal::of((string) $r->quantity)->toScale(4), 'amount' => (int) $r->amount, 'cost' => (int) ($cost[$r->item_id]->cost ?? 0),
            'margin' => (int) $r->amount - (int) ($cost[$r->item_id]->cost ?? 0),
        ])->sortByDesc(fn (array $r) => $sort === 'quantity' ? (float) $r['quantity'] : $r[$sort])->values();
    }

    /** Items with stock on hand and no delivery OUT in the last N days (the oldest last movement first). */
    public function leastTaken(array $filters, int $days = 90): Collection
    {
        $since = today()->subDays($days)->toDateString();
        $warehouses = $this->warehouseIds($filters);
        $onHand = ItemCost::query()->whereIn('warehouse_id', $warehouses)->where('qty_on_hand', '>', 0)
            ->selectRaw('item_id, SUM(qty_on_hand) AS qty, SUM(total_value) AS value')->groupBy('item_id')->get()->keyBy('item_id');
        if ($onHand->isEmpty()) {
            return collect();
        }
        $lastOut = StockMovement::query()->active()->where('direction', StockMovement::OUT)
            ->whereIn('warehouse_id', $warehouses)->whereIn('item_id', $onHand->keys())
            ->whereIn('source_line_type', ['delivery_line', 'sales_invoice_line'])
            ->selectRaw('item_id, MAX(trans_date) AS last_out')->groupBy('item_id')->get()->keyBy('item_id');
        $items = $this->items($filters)->whereIn('id', $onHand->keys())->get();
        $rows = collect();
        foreach ($items as $item) {
            $last = $lastOut->get($item->id)?->last_out;
            if ($last !== null && $last >= $since) {
                continue;
            }
            $rows->push([
                'key' => $item->id, 'item_id' => $item->id, 'number' => $item->number, 'name' => $item->name,
                'on_hand' => (string) BigDecimal::of((string) $onHand[$item->id]->qty)->toScale(4), 'value' => (int) $onHand[$item->id]->value,
                'last_out' => $last, 'idle_days' => $last === null ? null : (int) today()->diffInDays($last, true),
            ]);
        }

        return $rows->sortBy(fn (array $r) => $r['last_out'] ?? '0000-00-00')->values();
    }

    /** Items never on a sales invoice, with what they hold. */
    public function neverSold(array $filters): Collection
    {
        $sold = SalesInvoiceLine::query()->distinct()->pluck('item_id');
        $warehouses = $this->warehouseIds($filters);
        $onHand = ItemCost::query()->whereIn('warehouse_id', $warehouses)->selectRaw('item_id, SUM(qty_on_hand) AS qty, SUM(total_value) AS value')->groupBy('item_id')->get()->keyBy('item_id');

        return $this->items($filters)->whereNotIn('id', $sold)->orderBy('number')->get()->map(fn (Item $item) => [
            'key' => $item->id, 'item_id' => $item->id, 'number' => $item->number, 'name' => $item->name,
            'on_hand' => (string) BigDecimal::of((string) ($onHand[$item->id]->qty ?? 0))->toScale(4), 'value' => (int) ($onHand[$item->id]->value ?? 0),
        ])->values();
    }

    public function oldestStock(array $filters): Collection
    {
        return $this->age->rows(['warehouse_id' => $filters['warehouse_id'] ?? null, 'category_id' => $filters['category_id'] ?? null, 'brand_id' => $filters['brand_id'] ?? null]);
    }

    /** Returned quantity and value per item in the period, with the share of what was sold. */
    public function mostReturned(array $filters, Period $period): Collection
    {
        $sold = $this->invoiceLines($filters, $period)->selectRaw('sales_invoice_lines.item_id, SUM(sales_invoice_lines.base_quantity) AS quantity')->groupBy('sales_invoice_lines.item_id')->get()->keyBy('item_id');
        $returned = SalesReturnLine::query()
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_lines.sales_return_id')
            ->join('items', 'items.id', '=', 'sales_return_lines.item_id')
            ->whereBetween('sales_returns.trans_date', [$period->fromDate(), $period->untilDate()])
            ->when($period->branchId, fn (Builder $q) => $q->where('sales_returns.branch_id', $period->branchId))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('items.category_id', $id))
            ->when($filters['brand_id'] ?? null, fn (Builder $q, $id) => $q->where('items.brand_id', $id))
            ->selectRaw('sales_return_lines.item_id, items.number, items.name, COUNT(DISTINCT sales_returns.id) AS returns, SUM(sales_return_lines.base_quantity) AS quantity, SUM(sales_return_lines.amount) AS amount')
            ->groupBy('sales_return_lines.item_id', 'items.number', 'items.name')
            ->get();

        return $returned->map(function ($r) use ($sold) {
            $soldQty = BigDecimal::of((string) ($sold[$r->item_id]->quantity ?? 0));
            $qty = BigDecimal::of((string) $r->quantity);

            return [
                'key' => $r->item_id, 'item_id' => $r->item_id, 'number' => $r->number, 'name' => $r->name,
                'returns' => (int) $r->returns, 'quantity' => (string) $qty->toScale(4), 'amount' => (int) $r->amount,
                'sold' => (string) $soldQty->toScale(4),
                'rate' => $soldQty->isZero() ? null : (string) $qty->multipliedBy(100)->dividedBy($soldQty, 1, RoundingMode::HalfUp),
            ];
        })->sortByDesc(fn (array $r) => (float) $r['quantity'])->values();
    }

    /** Units sold in the period against the stock on hand: how many times the stock turned, and the months of cover. */
    public function turnover(array $filters, Period $period): Collection
    {
        $warehouses = $this->warehouseIds($filters);
        $onHand = ItemCost::query()->whereIn('warehouse_id', $warehouses)->selectRaw('item_id, SUM(qty_on_hand) AS qty')->groupBy('item_id')->get()->keyBy('item_id');
        $months = max(1, (int) ceil($period->from->diffInDays($period->until) / 30));

        return $this->invoiceLines($filters, $period)
            ->selectRaw('sales_invoice_lines.item_id, items.number, items.name, SUM(sales_invoice_lines.base_quantity) AS quantity')
            ->groupBy('sales_invoice_lines.item_id', 'items.number', 'items.name')->get()
            ->map(function ($r) use ($onHand, $months) {
                $stock = BigDecimal::of((string) ($onHand[$r->item_id]->qty ?? 0));
                $sold = BigDecimal::of((string) $r->quantity);
                $perMonth = $sold->dividedBy($months, 4, RoundingMode::HalfUp);

                return [
                    'key' => $r->item_id, 'item_id' => $r->item_id, 'number' => $r->number, 'name' => $r->name,
                    'sold' => (string) $sold->toScale(4), 'on_hand' => (string) $stock->toScale(4),
                    'turns' => $stock->isZero() ? null : (string) $sold->dividedBy($stock, 2, RoundingMode::HalfUp),
                    'months_cover' => $perMonth->isZero() ? null : (string) $stock->dividedBy($perMonth, 1, RoundingMode::HalfUp),
                ];
            })->sortByDesc(fn (array $r) => (float) ($r['turns'] ?? 0))->values();
    }

    private function invoiceLines(array $filters, Period $period): Builder
    {
        return SalesInvoiceLine::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->join('items', 'items.id', '=', 'sales_invoice_lines.item_id')
            ->whereBetween('sales_invoices.trans_date', [$period->fromDate(), $period->untilDate()])
            ->when($period->branchId, fn (Builder $q) => $q->where('sales_invoices.branch_id', $period->branchId))
            ->when($filters['warehouse_id'] ?? null, fn (Builder $q, $id) => $q->where('sales_invoice_lines.warehouse_id', $id))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('items.category_id', $id))
            ->when($filters['brand_id'] ?? null, fn (Builder $q, $id) => $q->where('items.brand_id', $id));
    }

    private function items(array $filters): Builder
    {
        return Item::query()->where('item_type', 'inventory')
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['brand_id'] ?? null, fn (Builder $q, $id) => $q->where('brand_id', $id));
    }

    /** @return Collection<int, int> */
    private function warehouseIds(array $filters): Collection
    {
        return Warehouse::query()->where('is_system', false)->where('is_active', true)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');
    }
}
