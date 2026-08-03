<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'sku', 'urutan', 'ordered_unit', 'ordered_qty',
    'qty_per_ctn_snapshot', 'satuan_dasar_snapshot', 'qty_base',
])]
class OrderLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordered_unit' => Unit::class,
            'ordered_qty' => 'integer',
            'qty_per_ctn_snapshot' => 'integer',
            'qty_base' => 'integer',
            'unit_price_rupiah' => 'decimal:4',
            'discount_rupiah' => 'integer',
            'line_total_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'price_reason_meta' => 'array',
            'priced_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    /** True once the line carries its own price snapshot. */
    public function isPriced(): bool
    {
        return $this->priced_at !== null;
    }
}
