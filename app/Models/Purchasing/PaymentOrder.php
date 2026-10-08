<?php

namespace App\Models\Purchasing;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Documents\PaymentMethod;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Payment Order: a batch of vendor invoices to pay by a date; Vendor Transfers turns it into payments. Not posted. */
class PaymentOrder extends Model implements HasAuditReference
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'total' => 'integer', 'payment_method' => PaymentMethod::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentOrderLine::class)->orderBy('sort');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function refreshTotal(): void
    {
        $lines = $this->lines()->get();
        $this->forceFill([
            'total' => (int) $lines->sum('amount'),
            'status' => $lines->isEmpty() ? 'pending' : ($lines->every(fn ($l) => $l->purchase_payment_id !== null) ? 'processed' : ($lines->contains(fn ($l) => $l->purchase_payment_id !== null) ? 'partial' : 'pending')),
        ])->saveQuietly();
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
