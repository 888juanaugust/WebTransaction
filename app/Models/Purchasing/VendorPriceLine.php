<?php

namespace App\Models\Purchasing;

use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPriceLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'decimal:4'];
    }

    public function vendorPrice(): BelongsTo
    {
        return $this->belongsTo(VendorPrice::class);
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
