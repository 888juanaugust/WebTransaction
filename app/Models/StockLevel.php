<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached aggregate of stock_movements. Never authoritative on its own —
 * StockLedger::reconcile() rebuilds it by summing the ledger.
 */
#[Fillable(['sku', 'warehouse_id', 'qty_on_hand', 'qty_reserved'])]
class StockLevel extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'integer',
            'qty_reserved' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    /** What a new order may actually take. */
    public function qtyAvailable(): int
    {
        return $this->qty_on_hand - $this->qty_reserved;
    }
}
