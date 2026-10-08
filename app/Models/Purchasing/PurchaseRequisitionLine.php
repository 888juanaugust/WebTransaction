<?php

namespace App\Models\Purchasing;

use App\Domain\Documents\DocumentLine;
use App\Models\Inventory\Item;
use App\Models\Inventory\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionLine extends Model
{
    use DocumentLine;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'base_quantity' => 'decimal:4', 'processed_quantity' => 'decimal:4', 'estimated_price' => 'decimal:4', 'requested_date' => 'date'];
    }

    public function document(): Model
    {
        return $this->purchaseRequisition;
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class);
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
