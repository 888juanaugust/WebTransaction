<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'supplier_bill_id', 'goods_receipt_line_id', 'jenis', 'sku', 'urutan', 'deskripsi',
    'qty_base', 'unit_cost_rupiah', 'line_total_rupiah', 'dpp_rupiah', 'ppn_rupiah',
])]
class SupplierBillLine extends Model
{
    use HasFactory;

    /** Goods, billed against a receipt. */
    public const JENIS_BARANG = 'barang';

    /**
     * A charge with no goods behind it — freight, duty, handling.
     *
     * These wait in the clearing account until an allocation spreads them over
     * the shipment they belong to.
     */
    public const JENIS_BIAYA = 'biaya';

    public function isBiaya(): bool
    {
        return $this->jenis === self::JENIS_BIAYA;
    }

    /** Has this charge already been spread over the goods? */
    public function isAllocated(): bool
    {
        return $this->landedCost()->where('status', LandedCost::STATUS_POSTED)->exists();
    }

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

    /** The allocation that spread this charge, if one has been drawn. */
    public function landedCost(): HasOne
    {
        return $this->hasOne(LandedCost::class);
    }
}
