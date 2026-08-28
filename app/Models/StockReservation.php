<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'order_line_id', 'sku', 'warehouse_id', 'qty_base',
    'status', 'resolved_at', 'resolution_reason',
])]
class StockReservation extends Model
{
    use HasFactory;
    use HasRegion;

    public const UPDATED_AT = null;

    public const STATUS_HELD = 'held';

    public const STATUS_RELEASED = 'released';

    public const STATUS_CONSUMED = 'consumed';

    protected function casts(): array
    {
        return [
            'qty_base' => 'integer',
            'created_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
