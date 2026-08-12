<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Moving-average cost as a (quantity, value) pair. Cached aggregate of
 * stock_movements — InventoryValuation::reconcile() rebuilds it by replay.
 */
#[Fillable(['sku', 'qty_base', 'value_rupiah', 'last_cost_rupiah', 'last_received_at'])]
class ProductCost extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty_base' => 'integer',
            'value_rupiah' => 'integer',
            'last_cost_rupiah' => 'integer',
            'last_received_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    /**
     * Cost of one base unit, derived rather than stored.
     *
     * Rounded half-up at the point of asking, so the rounding never compounds
     * back into the stored pair. Zero on hand means there is no average to
     * quote — the caller decides what to do about that.
     */
    public function unitCost(): int
    {
        if ($this->qty_base <= 0) {
            return 0;
        }

        return intdiv($this->value_rupiah * 2 + $this->qty_base, $this->qty_base * 2);
    }
}
