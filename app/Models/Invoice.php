<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'nomor', 'order_id', 'company_id', 'npwp', 'nama_wajib_pajak', 'alamat_pajak',
    'subtotal_rupiah', 'discount_rupiah', 'dpp_rupiah', 'ppn_rupiah', 'total_rupiah',
    'issued_on', 'due_date', 'kode_transaksi',
    // Written back after the Coretax round trip, which is the one thing that
    // legitimately changes on an already-issued invoice. `status` stays out —
    // that follows the payment ledger, not an assignment.
    'nsfp', 'faktur_exported_at',
])]
class Invoice extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected function casts(): array
    {
        return [
            'subtotal_rupiah' => 'integer',
            'discount_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'total_rupiah' => 'integer',
            'issued_on' => 'date',
            'due_date' => 'date',
            'faktur_exported_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentEntries(): HasMany
    {
        return $this->hasMany(PaymentEntry::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** Sum of the append-only payment ledger for this invoice. */
    public function amountPaid(): int
    {
        return (int) $this->paymentEntries()->sum('amount_rupiah');
    }

    /**
     * What has been credited back on posted notes.
     *
     * Drafts do not count. A draft is somebody's intention, and letting an
     * unfinished document lower a customer's balance is how a return that was
     * never agreed ends up reducing what they owe.
     */
    public function amountCredited(): int
    {
        return (int) $this->creditNotes()
            ->where('status', CreditNote::STATUS_POSTED)
            ->sum('total_rupiah');
    }

    /**
     * What is still owed: billed, less paid, less credited.
     *
     * Three other places need this same figure — the credit check, the ledger
     * reconciliation, and the portal — and OutstandingReceivables exists so
     * they are all one rule. This is the per-invoice form of it.
     */
    public function amountOutstanding(): int
    {
        return $this->total_rupiah - $this->amountPaid() - $this->amountCredited();
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)
            ->whereDate('due_date', '<', now()->toDateString());
    }
}
