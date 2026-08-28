<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. Never UPDATE, never DELETE — insert the opposite movement.
 */
#[Fillable([
    'sku', 'warehouse_id', 'qty_signed', 'unit_cost_rupiah', 'value_rupiah', 'reason',
    'reference_type', 'reference_id', 'actor_id', 'catatan',
])]
class StockMovement extends Model
{
    use HasFactory;
    use HasRegion;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'qty_signed' => 'integer',
            'unit_cost_rupiah' => 'integer',
            'value_rupiah' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'sku', 'kode');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
