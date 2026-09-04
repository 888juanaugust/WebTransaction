<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment settles one invoice.
 *
 * Append-only, like the entries it hangs off. Taking an allocation back
 * inserts its negative rather than editing or deleting this row, so every
 * sum over `amount_rupiah` is the net position and no query has to remember
 * to exclude something.
 */
#[Fillable([
    'payment_entry_id', 'invoice_id', 'amount_rupiah', 'actor_id',
    'reverses_allocation_id', 'catatan',
])]
class PaymentAllocation extends Model
{
    use HasFactory;
    use HasRegion;

    protected function casts(): array
    {
        return [
            'amount_rupiah' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function paymentEntry(): BelongsTo
    {
        return $this->belongsTo(PaymentEntry::class);
    }

    /**
     * The invoice this settles.
     *
     * Region scope lifted, matching `Invoice::company()`: after a warehouse
     * split a customer's fakturs can sit in another region's books, and one
     * transfer settles whichever of their fakturs they name. The allocation's
     * own region column is the gate.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('region');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** What this row takes back, when it is a reversal. */
    public function reversesAllocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_allocation_id');
    }
}
