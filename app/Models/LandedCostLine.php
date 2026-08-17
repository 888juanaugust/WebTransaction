<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receipt line's share of a charge, and how that share split.
 *
 * `ke_persediaan` and `ke_hpp` are filled at posting and never recomputed:
 * they depend on how much of that SKU was still on the shelf at that instant,
 * and asking the same question tomorrow gives a different answer.
 */
#[Fillable([
    'landed_cost_id', 'goods_receipt_line_id', 'sku', 'urutan', 'warehouse_id',
    'dasar_nilai', 'qty_base', 'amount_rupiah',
])]
class LandedCostLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'dasar_nilai' => 'integer',
            'qty_base' => 'integer',
            'amount_rupiah' => 'integer',
            'qty_on_hand' => 'integer',
            'ke_persediaan_rupiah' => 'integer',
            'ke_hpp_rupiah' => 'integer',
        ];
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }
}
