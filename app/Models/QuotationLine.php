<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'quotation_id', 'urutan', 'sku', 'ordered_unit', 'ordered_qty', 'qty_base',
    'qty_per_ctn_snapshot', 'satuan_dasar_snapshot', 'unit_price_rupiah',
    'discount_rupiah', 'line_total_rupiah', 'dpp_rupiah', 'ppn_rupiah',
    'price_reason', 'merk_snapshot', 'description_snapshot',
])]
class QuotationLine extends Model
{
    use HasFactory;

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }
}
