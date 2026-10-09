<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\Sales\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer seen from its team: the base's customer with its two seats as relations. */
class TeamCustomer extends Customer
{
    protected $table = 'customers';

    public function salesUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function marketingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_user_id');
    }
}
