<?php

namespace App\Models\Sales;

use App\Domain\Audit\HasAuditReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Price / Discount Adjustment: new prices or discounts for a price category from a date; the resolver reads them. */
class SellingPriceAdjustment extends Model implements HasAuditReference
{
    public const PRICE = 'price';

    public const DISCOUNT = 'discount';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'end_date' => 'date', 'is_active' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SellingPriceAdjustmentLine::class)->orderBy('sort');
    }

    public function priceCategory(): BelongsTo
    {
        return $this->belongsTo(PriceCategory::class);
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
