<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Units\UnitConverter;
use App\Domain\Posting\DocumentRepository;
use App\Models\Inventory\InventoryAdjustment;
use App\Models\Inventory\InventoryAdjustmentLine;
use App\Models\Inventory\Item;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns an item's "Stock" tab (quantities at the data start date) into one
 * posted opening adjustment per item, kept in step every time the item is
 * saved. After that, stock only moves through documents.
 */
final class OpeningStockPoster
{
    public function __construct(private readonly DocumentRepository $documents) {}

    public function postFor(Item $item): ?InventoryAdjustment
    {
        return DB::transaction(function () use ($item): ?InventoryAdjustment {
            $item->load(['openingStocks', 'units']);
            $existing = InventoryAdjustment::query()->where('opening_item_id', $item->id)->first();

            if ($item->openingStocks->isEmpty()) {
                if ($existing !== null) {
                    $this->documents->delete($existing);
                }

                return null;
            }

            $date = $item->openingStocks->min('trans_date');
            $lines = $item->openingStocks->map(fn ($s, int $i) => [
                'sort' => $i,
                'item_id' => $item->id,
                'adjustment_type' => InventoryAdjustmentLine::QUANTITY,
                'quantity' => (string) $s->quantity,
                'unit_id' => $s->unit_id ?? $item->unit1_id,
                'base_quantity' => UnitConverter::toBase($item, (string) $s->quantity, $s->unit_id ?? $item->unit1_id),
                'unit_cost' => (string) BigDecimal::of((string) $s->unit_cost)->dividedBy(
                    UnitConverter::ratio($item, $s->unit_id ?? $item->unit1_id), 4, RoundingMode::HalfUp),
                'total_cost' => BigDecimal::of((string) $s->unit_cost)->multipliedBy((string) $s->quantity)->toScale(0, RoundingMode::HalfUp)->toInt(),
                'warehouse_id' => $s->warehouse_id,
                'memo' => 'Opening stock',
            ])->all();

            if ($existing === null) {
                $adjustment = InventoryAdjustment::query()->create([
                    'number' => 'OPENING-'.$item->number,
                    'trans_date' => $date,
                    'description' => "Opening stock of {$item->number} {$item->name}",
                    'is_opening' => true,
                    'opening_item_id' => $item->id,
                    'created_by' => auth()->id(),
                ]);
                $adjustment->lines()->createMany($lines);
                $this->documents->created($adjustment);

                return $adjustment;
            }

            $before = $this->documents->beforeUpdate($existing, CarbonImmutable::parse($date));
            $existing->update(['trans_date' => $date, 'updated_by' => auth()->id()]);
            $existing->lines()->delete();
            $existing->lines()->createMany($lines);
            $this->documents->updated($existing->fresh(), $before);

            return $existing;
        });
    }
}
