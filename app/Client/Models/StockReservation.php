<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\GeneralLedger\Posting;
use App\Models\Inventory\Item;
use App\Models\Inventory\Warehouse;
use App\Models\Sales\SalesOrder;
use App\Models\Sales\SalesOrderLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of the stock reservations ledger; signed, never edited. */
class StockReservation extends Model
{
    public const HELD = 'held';

    public const CONSUMED = 'consumed';

    public const RELEASED = 'released';

    public const REOPENED = 'reopened';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'created_at' => 'datetime'];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }
}
