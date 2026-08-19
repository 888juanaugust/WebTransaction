<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. A payment row is never mutated — to undo one, insert a
 * reversing entry with the opposite amount and reverses_entry_id set.
 */
#[Fillable([
    'company_id', 'invoice_id', 'order_id', 'amount_rupiah', 'kind',
    'gateway', 'gateway_reference', 'webhook_event_id', 'actor_id',
    'reverses_entry_id', 'paid_at', 'catatan',
])]
class PaymentEntry extends Model
{
    use HasFactory;

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

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    /** Money in that has not been matched to an invoice yet. */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('invoice_id')->where('kind', self::KIND_PAYMENT);
    }
}
