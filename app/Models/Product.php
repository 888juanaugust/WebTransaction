<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Uom\Unit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * KODE is the primary key. One row = one KODE.
 */
#[Fillable([
    'kode', 'merk', 'kategori', 'tipe_produk', 'mobil', 'part_number',
    'description', 'qty_per_ctn', 'satuan_dasar', 'aktif', 'catatan',
    'titik_pesan_ulang_manual', 'jangan_pesan_ulang',
])]
class Product extends Model
{
    use HasFactory;

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'qty_per_ctn' => 'integer',
            'aktif' => 'boolean',
            'titik_pesan_ulang_manual' => 'integer',
            'jangan_pesan_ulang' => 'boolean',
        ];
    }

    public function baseUnit(): Unit
    {
        return Unit::from($this->satuan_dasar);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'sku', 'kode');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sku', 'kode');
    }

    /** Moving-average cost. Null until this SKU has ever been received. */
    public function cost(): HasOne
    {
        return $this->hasOne(ProductCost::class, 'sku', 'kode');
    }
}
