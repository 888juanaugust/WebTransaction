<?php

namespace App\Models\Inventory;

use App\Domain\Audit\RecordsChildActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemUnit extends Model
{
    use RecordsChildActivity;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ratio' => 'decimal:6', 'sell_price' => 'integer'];
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
