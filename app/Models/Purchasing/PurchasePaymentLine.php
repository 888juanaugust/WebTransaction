<?php

namespace App\Models\Purchasing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PurchasePaymentLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'discount' => 'integer'];
    }

    public function purchasePayment(): BelongsTo
    {
        return $this->belongsTo(PurchasePayment::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
