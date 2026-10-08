<?php

namespace App\Models\Sales;

use App\Models\Company\Employee;
use App\Models\Inventory\Item;
use App\Models\Inventory\ItemCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTargetLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'value' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function itemCategory(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class);
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
    }
}
