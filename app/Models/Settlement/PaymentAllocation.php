<?php

namespace App\Models\Settlement;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One application of a payment to an invoice. Append-only; counts while its posting is active. */
class PaymentAllocation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'discount' => 'integer', 'trans_date' => 'date'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereExists(fn ($q) => $q
            ->selectRaw('1')
            ->from('postings')
            ->whereColumn('postings.id', 'payment_allocations.posting_id')
            ->whereNull('postings.superseded_at'));
    }

    public function receivable(): MorphTo
    {
        return $this->morphTo();
    }

    public function payment(): MorphTo
    {
        return $this->morphTo();
    }
}
