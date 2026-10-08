<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Costing;

use App\Domain\Shared\Money;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCost;
use App\Models\Inventory\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The moving average per item per warehouse, replayed from the stock ledger in
 * document-date order with receipts before issues on the same day (the one
 * choice on open question I-10, kept here). An issue takes the average of
 * everything dated up to its day; its cost is fixed in its posting, and a
 * back-dated change re-posts the issues after it (Recoster).
 */
final class CostEngine
{
    /** @return array{qty: string, value: int, avg: string} */
    public function replay(int $itemId, int $warehouseId, DateTimeInterface|string|null $upTo = null): array
    {
        $qty = BigDecimal::zero();
        $value = 0;

        $movements = StockMovement::query()->active()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->when($upTo, fn ($q) => $q->where('trans_date', '<=', Carbon::parse($upTo)->toDateString()))
            ->orderBy('trans_date')
            ->orderByRaw("CASE WHEN direction = 'in' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get(['direction', 'base_quantity', 'total_cost']);

        foreach ($movements as $m) {
            $q = BigDecimal::of((string) $m->base_quantity);
            if ($m->direction === StockMovement::IN) {
                $qty = $qty->plus($q);
                $value += (int) $m->total_cost;
            } else {
                $qty = $qty->minus($q);
                $value -= (int) $m->total_cost;
            }
        }

        return ['qty' => (string) $qty->toScale(4), 'value' => $value, 'avg' => self::average($qty, $value)];
    }

    /** The unit cost an issue dated $date carries: the average of everything up to and including that day. */
    public function costAt(int $itemId, int $warehouseId, DateTimeInterface|string $date): string
    {
        return $this->replay($itemId, $warehouseId, $date)['avg'];
    }

    /**
     * The cost of issuing $quantity on $date: quantity × average, half-up, and
     * the whole remaining value when the issue empties the warehouse, so no
     * rupiah is left behind.
     */
    public function issueCost(int $itemId, int $warehouseId, DateTimeInterface|string $date, string $quantity): array
    {
        $state = $this->replay($itemId, $warehouseId, $date);
        $qty = BigDecimal::of($quantity);
        $onHand = BigDecimal::of($state['qty']);
        // With nothing on hand there is no average: what goes out (negative stock allowed) is costed at the item's
        // purchase price, never at zero.
        $avg = BigDecimal::of($state['avg'])->isPositive() ? $state['avg'] : (string) (Item::query()->whereKey($itemId)->value('purchase_price') ?? '0');

        if ($onHand->isPositive() && $qty->isGreaterThanOrEqualTo($onHand)) {
            // All that is on hand leaves at its whole value (no rounding residue); anything beyond, at the average.
            $beyond = BigDecimal::of($avg)->multipliedBy($qty->minus($onHand))->toScale(0, RoundingMode::HalfUp)->toInt();

            return ['unit_cost' => $state['avg'], 'total_cost' => $state['value'] + $beyond];
        }

        $total = BigDecimal::of($avg)->multipliedBy($qty)->toScale(0, RoundingMode::HalfUp)->toInt();

        return ['unit_cost' => (string) BigDecimal::of($avg)->toScale(4, RoundingMode::HalfUp), 'total_cost' => $total];
    }

    public function refreshCache(int $itemId, int $warehouseId): ItemCost
    {
        $state = $this->replay($itemId, $warehouseId);

        // The cache has a composite key, so it is written with an upsert, never through a model save.
        ItemCost::query()->upsert(
            [['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'qty_on_hand' => $state['qty'], 'avg_cost' => $state['avg'], 'total_value' => $state['value'], 'updated_at' => now()]],
            ['item_id', 'warehouse_id'],
            ['qty_on_hand', 'avg_cost', 'total_value', 'updated_at'],
        );

        return ItemCost::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->firstOrFail();
    }

    private static function average(BigDecimal $qty, int $value): string
    {
        if (! $qty->isPositive()) {
            return '0.0000';
        }

        return (string) BigDecimal::of($value)->dividedBy($qty, 4, RoundingMode::HalfUp);
    }

    /** Whole-rupiah money from a decimal product. */
    public static function money(string|int|float $amount): int
    {
        return Money::parse(is_string($amount) ? str_replace(',', '.', $amount) : $amount);
    }
}
