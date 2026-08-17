<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a purchase return, pointing at the receipt line it sends back.
 *
 * A draft carries only `qty_base` and the snapshots that came off the receipt.
 * Everything with money on it is written by PurchaseReturnPoster.
 */
#[Fillable([
    'purchase_return_id', 'goods_receipt_line_id', 'sku', 'urutan', 'deskripsi',
    'ordered_unit', 'ordered_qty', 'qty_per_ctn_snapshot', 'qty_base',
])]
class PurchaseReturnLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'ordered_unit' => Unit::class,
            'ordered_qty' => 'integer',
            'qty_per_ctn_snapshot' => 'integer',
            'qty_base' => 'integer',
            'qty_ditagih' => 'integer',
            'nilai_ditagih_rupiah' => 'integer',
            'nilai_belum_ditagih_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'unit_cost_rupiah' => 'integer',
            'nilai_persediaan_rupiah' => 'integer',
        ];
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }

    /** The bill this credits, or null where the goods were never billed. */
    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    /** The quantity whose accrual this line unwinds rather than a real debt. */
    public function qtyBelumDitagih(): int
    {
        return max(0, $this->qty_base - $this->qty_ditagih);
    }
}
