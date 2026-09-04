<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money out, append-only. Never mutate a row; insert a reversing entry.
 */
#[Fillable([
    'supplier_id', 'supplier_bill_id', 'amount_rupiah', 'kind',
    'referensi', 'actor_id', 'reverses_entry_id', 'paid_at', 'bank_account_id', 'catatan',
])]
class SupplierPaymentEntry extends Model
{
    use HasFactory;
    use HasRegion;

    public const UPDATED_AT = null;

    public const KIND_PAYMENT = 'payment';

    public const KIND_REVERSAL = 'reversal';

    public const KIND_ADJUSTMENT = 'adjustment';

    protected function casts(): array
    {
        return [
            'amount_rupiah' => 'integer',
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class, 'supplier_payment_entry_id');
    }

    /**
     * Money out with some of it discharging nothing.
     *
     * Not `supplier_bill_id IS NULL`: that asked whether anybody had named a
     * tagihan, which says nothing about how much of the payment the naming
     * used. A transfer pointed at a bill smaller than itself looked handled
     * while the rest of it was applied to no debt at all.
     */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query
            ->where('kind', self::KIND_PAYMENT)
            ->whereRaw(
                'supplier_payment_entries.amount_rupiah > COALESCE((
                    SELECT SUM(amount_rupiah) FROM supplier_payment_allocations
                    WHERE supplier_payment_allocations.supplier_payment_entry_id
                          = supplier_payment_entries.id
                ), 0)'
            );
    }
}
