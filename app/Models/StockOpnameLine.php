<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_opname_id', 'sku', 'urutan', 'qty_system', 'qty_counted',
    'selisih_qty', 'unit_cost_rupiah', 'selisih_rupiah', 'catatan',
])]
class StockOpnameLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'qty_system' => 'integer',
            'qty_counted' => 'integer',
            'selisih_qty' => 'integer',
            'unit_cost_rupiah' => 'integer',
            'selisih_rupiah' => 'integer',
        ];
    }

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    /** Counted less system, once somebody has counted. Null while blank. */
    public function variance(): ?int
    {
        return $this->qty_counted === null ? null : $this->qty_counted - $this->qty_system;
    }

    public function isCounted(): bool
    {
        return $this->qty_counted !== null;
    }
}
