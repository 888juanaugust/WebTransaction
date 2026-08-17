<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_transfer_id', 'sku', 'urutan', 'ordered_unit', 'ordered_qty',
    'qty_per_ctn_snapshot', 'qty_base', 'unit_cost_rupiah', 'line_value_rupiah', 'catatan',
])]
class StockTransferLine extends Model
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

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }
}
