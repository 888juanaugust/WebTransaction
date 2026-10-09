<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\Sales\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A buyer's cart: one per login, emptied by checkout, pruned when untouched for long. */
class PortalCart extends Model
{
    protected $guarded = [];

    public function lines(): HasMany
    {
        return $this->hasMany(PortalCartLine::class, 'cart_id')->orderBy('id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class, 'customer_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
