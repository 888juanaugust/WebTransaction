<?php

namespace App\Models\Inventory;

use App\Domain\Audit\RecordsChildActivity;
use App\Models\Sales\PriceCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemPrice extends Model
{
    use RecordsChildActivity;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'integer'];
    }

    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function auditParent(): ?Model
    {
        return Item::query()->find($this->getAttribute('item_id'));
    }
}
