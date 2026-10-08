<?php

namespace App\Models\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SalesReceiptLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'discount' => 'integer'];
    }

    public function salesReceipt(): BelongsTo
    {
        return $this->belongsTo(SalesReceipt::class);
    }

    public function receivable(): MorphTo
    {
        return $this->morphTo();
    }
}
