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
 * Append-only. A payment row is never mutated — to undo one, insert a
 * reversing entry with the opposite amount and reverses_entry_id set.
 */
#[Fillable([
    'company_id', 'invoice_id', 'order_id', 'amount_rupiah', 'kind',
    'actor_id', 'reverses_entry_id', 'paid_at', 'bank_account_id', 'catatan',
])]
class PaymentEntry extends Model
{
    use HasFactory;
    use HasRegion;

    public const UPDATED_AT = null;

    public const KIND_PAYMENT = 'payment';

    public const KIND_REVERSAL = 'reversal';

    public const KIND_ADJUSTMENT = 'adjustment';

    public const KIND_WRITEOFF = 'writeoff';

    /**
     * A deposit being applied to an invoice.
     *
     * It is a payment entry because every consumer of this table — the invoice
     * settlement, the ageing report, the customer statement, the portal — is
     * asking "what has come off this invoice", and the answer is the same
     * whether the money arrived today or three weeks ago. But no money moves
     * on the day it is applied, so it posts a different journal: the cash was
     * already banked when the deposit was taken.
     */
    public const KIND_DEPOSIT_APPLICATION = 'deposit_application';

    protected function casts(): array
    {
        return [
            'amount_rupiah' => 'integer',
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Money in with some of it still applied to nothing.
     *
     * Not `invoice_id IS NULL` any more. That asked whether anybody had
     * *named* a faktur, which said nothing about how much of the money the
     * naming actually used: a Rp 50.000.000 transfer pointed at a
     * Rp 12.000.000 bill left the queue looking handled with Rp 38.000.000
     * of the customer's money sitting on no invoice at all. A partly applied
     * transfer belongs in this queue exactly as much as an untouched one,
     * because in both cases there is money nobody has accounted for.
     */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query
            ->where('kind', self::KIND_PAYMENT)
            ->whereRaw(
                'payment_entries.amount_rupiah > COALESCE((
                    SELECT SUM(amount_rupiah) FROM payment_allocations
                    WHERE payment_allocations.payment_entry_id = payment_entries.id
                ), 0)'
            );
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
