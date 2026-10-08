<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The cached quantity, average cost and value of an item in a warehouse; rebuilt from the ledger. */
class ItemCost extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty_on_hand' => 'decimal:4', 'avg_cost' => 'decimal:4', 'total_value' => 'integer', 'updated_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** What a unit of the item costs in the warehouse now: its moving average there, else the item's purchase price. */
    public static function current(int $itemId, ?int $warehouseId): string
    {
        $average = $warehouseId !== null ? static::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->value('avg_cost') : null;

        return (string) ($average ?? Item::query()->whereKey($itemId)->value('purchase_price') ?? 0);
    }
}
