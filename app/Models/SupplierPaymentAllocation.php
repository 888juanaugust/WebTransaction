<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one payment to a supplier discharges one of their bills.
 *
 * Append-only, like the entries it hangs off: taking an application back
 * inserts its negative rather than editing this row, so every sum is the net
 * position and no query has a validity flag to forget.
 */
#[Fillable([
    'supplier_payment_entry_id', 'supplier_bill_id', 'amount_rupiah',
    'actor_id', 'reverses_allocation_id', 'catatan',
])]
class SupplierPaymentAllocation extends Model
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
        return $this->belongsTo(SupplierPaymentEntry::class, 'supplier_payment_entry_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
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
