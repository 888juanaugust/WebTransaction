<?php

declare(strict_types=1);

namespace App\Client\Domain\Stock;

use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How old the stock on hand is, read from the ledger: every receipt opens
 * a layer dated the day it entered the warehouse, every issue consumes the
 * oldest layers first, and what remains is the stock on hand with its age.
 * Quantity only; the books keep their moving average. Nothing is stored.
 */
final class StockAge
{
    /** The bucket edges in days; a layer older than the last edge is the last bucket. */
    public const EDGES = [30, 90, 180, 365];

    /**
     * The layers still on hand of an item in a warehouse, oldest first.
     *
     * @return list<array{date: string, quantity: string, days: int}>
     */
    public function layers(int $itemId, int $warehouseId, CarbonImmutable|string|null $asOf = null): array
    {
        $asOf = $asOf === null ? CarbonImmutable::today() : CarbonImmutable::parse((string) ($asOf instanceof CarbonImmutable ? $asOf->toDateString() : $asOf));
        $movements = StockMovement::query()->active()
            ->where('item_id', $itemId)->where('warehouse_id', $warehouseId)
            ->whereDate('trans_date', '<=', $asOf->toDateString())
            ->orderBy('trans_date')
            ->orderByRaw("CASE WHEN direction = 'in' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['direction', 'base_quantity', 'trans_date']);

        /** @var list<array{date: string, quantity: BigDecimal}> $layers */
        $layers = [];
        foreach ($movements as $m) {
            $qty = BigDecimal::of((string) $m->base_quantity)->abs();
            if ($qty->isZero()) {
                continue; // a value adjustment opens no layer
            }
            if ($m->direction === StockMovement::IN) {
                $layers[] = ['date' => $m->trans_date->toDateString(), 'quantity' => $qty];

                continue;
            }
            // An issue takes from the oldest layers first.
            $left = $qty;
            foreach ($layers as $i => $layer) {
                if ($left->isZero()) {
                    break;
                }
                $take = $layer['quantity']->isLessThanOrEqualTo($left) ? $layer['quantity'] : $left;
                $layers[$i]['quantity'] = $layer['quantity']->minus($take);
                $left = $left->minus($take);
            }
            $layers = array_values(array_filter($layers, fn (array $l) => $l['quantity']->isPositive()));
        }

        return array_map(fn (array $l) => [
            'date' => $l['date'],
            'quantity' => (string) $l['quantity']->toScale(4, RoundingMode::HalfUp),
            'days' => (int) CarbonImmutable::parse($l['date'])->diffInDays($asOf, false),
        ], $layers);
    }

    /** The bucket a number of days falls in, as its label key. */
    public static function bucket(int $days): string
    {
        $from = 0;
        foreach (self::EDGES as $edge) {
            if ($days <= $edge) {
                return "{$from}-{$edge}";
            }
            $from = $edge + 1;
        }

        return 'over-'.self::EDGES[count(self::EDGES) - 1];
    }

    /** @return array<string, string> bucket key → label */
    public static function bucketLabels(): array
    {
        $labels = [];
        $from = 0;
        foreach (self::EDGES as $edge) {
            $labels["{$from}-{$edge}"] = __(':from–:to days', ['from' => $from, 'to' => $edge]);
            $from = $edge + 1;
        }
        $last = self::EDGES[count(self::EDGES) - 1];
        $labels['over-'.$last] = __('over :days days', ['days' => $last]);

        return $labels;
    }

    /**
     * One row per item and warehouse with stock on hand: the oldest layer's date and age, the quantity by bucket, the value.
     *
     * @param  array{warehouse_id?: int|null, category_id?: int|null, brand_id?: int|null, bucket?: string|null}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $filters = [], CarbonImmutable|string|null $asOf = null): Collection
    {
        $warehouses = Warehouse::query()->where('is_system', false)->where('is_active', true)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->pluck('name', 'id');
        $costs = ItemCost::query()->with(['item.unit1', 'item.category', 'item.brand'])
            ->whereIn('warehouse_id', $warehouses->keys())
            ->where('qty_on_hand', '>', 0)
            ->whereHas('item', fn ($q) => $q
                ->when($filters['category_id'] ?? null, fn ($i, $id) => $i->where('category_id', $id))
                ->when($filters['brand_id'] ?? null, fn ($i, $id) => $i->where('brand_id', $id)))
            ->get();

        $rows = collect();
        foreach ($costs as $cost) {
            $layers = $this->layers($cost->item_id, $cost->warehouse_id, $asOf);
            if ($layers === []) {
                continue;
            }
            $oldest = $layers[0];
            $byBucket = array_fill_keys(array_keys(self::bucketLabels()), BigDecimal::zero());
            foreach ($layers as $layer) {
                $key = self::bucket($layer['days']);
                $byBucket[$key] = $byBucket[$key]->plus($layer['quantity']);
            }
            $bucket = self::bucket($oldest['days']);
            if (($filters['bucket'] ?? null) && $filters['bucket'] !== $bucket) {
                continue;
            }
            $onHand = BigDecimal::of((string) $cost->qty_on_hand);
            $rows->push([
                'key' => $cost->item_id.'-'.$cost->warehouse_id,
                'item_id' => $cost->item_id,
                'number' => $cost->item?->number,
                'name' => $cost->item?->name,
                'unit' => $cost->item?->unit1?->name,
                'category' => $cost->item?->category?->name,
                'brand' => $cost->item?->brand?->name,
                'warehouse_id' => $cost->warehouse_id,
                'warehouse' => $warehouses[$cost->warehouse_id] ?? '',
                'on_hand' => (string) $onHand->toScale(4, RoundingMode::HalfUp),
                'oldest_date' => $oldest['date'],
                'oldest_days' => $oldest['days'],
                'bucket' => $bucket,
                'buckets' => array_map(fn (BigDecimal $q) => (string) $q->toScale(4, RoundingMode::HalfUp), $byBucket),
                'avg_cost' => (string) $cost->avg_cost,
                'value' => (int) $onHand->multipliedBy((string) $cost->avg_cost)->toScale(0, RoundingMode::HalfUp)->toInt(),
            ]);
        }

        return $rows->sortByDesc('oldest_days')->values();
    }
}
