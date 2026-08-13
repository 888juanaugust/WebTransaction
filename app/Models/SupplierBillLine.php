<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_bill_id', 'goods_receipt_line_id', 'sku', 'urutan', 'deskripsi',
    'qty_base', 'unit_cost_rupiah', 'line_total_rupiah', 'dpp_rupiah', 'ppn_rupiah',
])]
class SupplierBillLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty_base' => 'integer',
            'unit_cost_rupiah' => 'integer',
            'line_total_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
        ];
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    /** The receipt line this bills for, if it bills for goods at all. */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }
}
