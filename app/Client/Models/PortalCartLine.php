<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a cart: so much of one item in one of its units. The price is never stored here. */
class PortalCartLine extends Model
{
    protected $guarded = [];

    protected $touches = ['cart'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(PortalCart::class, 'cart_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
