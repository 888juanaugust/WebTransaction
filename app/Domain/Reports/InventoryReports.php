<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Shared\Format;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

/** Stock card (R-13) and inventory value per warehouse (R-14), from the stock ledger and its cost cache. */
final class InventoryReports
{
    /** One item's movements with running quantity and value. @return list<array<string, mixed>> */
    public static function stockCard(int $itemId, Period $period, ?int $warehouseId = null): array
    {
        $before = StockMovement::query()->active()
            ->where('item_id', $itemId)
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->where('trans_date', '<', $period->fromDate())
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN base_quantity ELSE -base_quantity END), 0) AS qty, COALESCE(SUM(CASE WHEN direction = 'in' THEN total_cost ELSE -total_cost END), 0) AS value")
            ->first();
        $qty = BigDecimal::of((string) ($before->qty ?? '0'));
        $value = (int) ($before->value ?? 0);
        $rows = [['id' => 0, 'trans_date' => $period->from, 'source' => 'Opening balance', 'warehouse' => '', 'in' => '', 'out' => '', 'unit_cost' => '', 'balance_qty' => (string) $qty->toScale(4), 'balance_value' => $value]];

        $movements = StockMovement::query()->active()
            ->with(['warehouse', 'posting.document'])
            ->where('item_id', $itemId)
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->whereBetween('trans_date', [$period->fromDate(), $period->untilDate()])
            ->orderBy('trans_date')->orderByRaw("CASE WHEN direction = 'in' THEN 0 ELSE 1 END")->orderBy('id')
            ->get();
        foreach ($movements as $m) {
            $signed = $m->direction === 'in' ? BigDecimal::of((string) $m->base_quantity) : BigDecimal::of((string) $m->base_quantity)->negated();
            $qty = $qty->plus($signed);
            $value += $m->direction === 'in' ? (int) $m->total_cost : -(int) $m->total_cost;
            $rows[] = [
                'id' => $m->id, 'trans_date' => $m->trans_date, 'source' => self::sourceLabel($m), 'warehouse' => $m->warehouse?->name,
                'in' => $m->direction === 'in' ? (string) $m->base_quantity : '', 'out' => $m->direction === 'out' ? (string) $m->base_quantity : '',
                'unit_cost' => (string) $m->unit_cost, 'balance_qty' => (string) $qty->toScale(4), 'balance_value' => $value,
            ];
        }

        return $rows;
    }

    /** Quantity and value per item per warehouse, from the cost cache (the ledger's sum). @return list<array<string, mixed>> */
    public static function inventoryValue(?int $warehouseId = null, ?int $categoryId = null): array
    {
        $rows = ItemCost::query()->with(['item.category', 'warehouse'])
            ->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))
            ->when($categoryId, fn (Builder $q) => $q->whereHas('item', fn (Builder $i) => $i->where('item_category_id', $categoryId)))
            ->where('qty_on_hand', '!=', 0)
            ->get()
            ->sortBy(fn (ItemCost $c) => ($c->item?->number ?? '').' '.($c->warehouse?->name ?? ''))
            ->values();
        $out = [];
        $totalValue = 0;
        foreach ($rows as $c) {
            $out[] = [
                'id' => $c->id ?? "{$c->item_id}-{$c->warehouse_id}", 'number' => $c->item?->number, 'name' => $c->item?->name, 'category' => $c->item?->category?->name,
                'warehouse' => $c->warehouse?->name, 'quantity' => (string) $c->qty_on_hand, 'avg_cost' => (string) $c->avg_cost, 'value' => (int) $c->total_value,
            ];
            $totalValue += (int) $c->total_value;
        }
        $out[] = ['id' => 'total', 'number' => 'Total', 'name' => '', 'category' => '', 'warehouse' => '', 'quantity' => '', 'avg_cost' => '', 'value' => $totalValue, 'is_total' => true];

        return $out;
    }

    private static function sourceLabel(StockMovement $m): string
    {
        $number = $m->posting?->document?->getAttribute('number') ?? '';

        return trim(Format::documentType((string) ($m->posting?->document_type ?? $m->source_line_type)).' '.$number);
    }

    /** @return array<int, string> */
    public static function warehouseOptions(): array
    {
        return Warehouse::query()->where('is_system', false)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    public static function itemOptions(string $search): array
    {
        return Item::query()->where(fn (Builder $q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%"))
            ->orderBy('number')->limit(30)->get()->mapWithKeys(fn (Item $i) => [$i->id => "{$i->number} · {$i->name}"])->all();
    }
}
