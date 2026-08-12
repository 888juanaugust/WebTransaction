<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'goods_receipt_id', 'sku', 'urutan', 'ordered_unit', 'ordered_qty',
    'qty_per_ctn_snapshot', 'satuan_dasar_snapshot', 'qty_base',
    'unit_cost_rupiah', 'line_value_rupiah', 'catatan',
])]
class GoodsReceiptLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordered_unit' => Unit::class,
            'ordered_qty' => 'integer',
            'qty_per_ctn_snapshot' => 'integer',
            'qty_base' => 'integer',
            'unit_cost_rupiah' => 'integer',
            'line_value_rupiah' => 'integer',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    /**
     * What one base unit of this line cost, derived from the line value.
     *
     * Never stored: the supplier quotes a price per carton, and dividing that
     * into a per-piece figure at write time would round once per line and leave
     * the stored value disagreeing with the stored cost.
     */
    public function unitCostPerBase(): int
    {
        if ($this->qty_base <= 0) {
            return 0;
        }

        return intdiv($this->line_value_rupiah * 2 + $this->qty_base, $this->qty_base * 2);
    }
}
