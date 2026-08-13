<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id', 'sku', 'urutan', 'ordered_unit', 'ordered_qty',
    'qty_per_ctn_snapshot', 'satuan_dasar_snapshot', 'qty_base',
    'unit_cost_rupiah', 'line_value_rupiah', 'catatan',
])]
class PurchaseOrderLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordered_unit' => Unit::class,
            'ordered_qty' => 'integer',
            'qty_per_ctn_snapshot' => 'integer',
            'qty_base' => 'integer',
            'qty_base_received' => 'integer',
            'unit_cost_rupiah' => 'integer',
            'line_value_rupiah' => 'integer',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    /** Base units still to come. Never negative — an over-delivery is not a debt. */
    public function outstandingQty(): int
    {
        return max(0, $this->qty_base - $this->qty_base_received);
    }

    /** Delivered more than was ordered. Worth surfacing; not an error. */
    public function isOverReceived(): bool
    {
        return $this->qty_base_received > $this->qty_base;
    }

    /** Agreed cost of one base unit, derived from the line value. */
    public function unitCostPerBase(): int
    {
        if ($this->qty_base <= 0) {
            return 0;
        }

        return intdiv($this->line_value_rupiah * 2 + $this->qty_base, $this->qty_base * 2);
    }
}
