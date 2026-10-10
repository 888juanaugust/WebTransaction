<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\Sales\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What the 30-day watch told the administrators about one order, once. */
class OrderDeliveryNotice extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_to' => 'array', 'sent_at' => 'datetime', 'created_at' => 'datetime', 'days' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }
}
