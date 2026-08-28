<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A buyer's basket. Holds quantities, never prices — see the migration.
 */
#[Fillable(['company_id', 'customer_user_id', 'warehouse_id'])]
class Cart extends Model
{
    use HasFactory;
    use HasRegion;

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customerUser(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isEmpty(): bool
    {
        return ! $this->items()->exists();
    }
}
