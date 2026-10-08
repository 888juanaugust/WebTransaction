<?php

namespace App\Models\Inventory;

use App\Domain\Audit\RecordsChildActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemComponent extends Model
{
    use RecordsChildActivity;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function auditParent(): ?Model
    {
        return Item::query()->find($this->getAttribute('group_item_id'));
    }
}
