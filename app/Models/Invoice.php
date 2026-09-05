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
    use HasRegion;

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

    /**
     * Region-free, by the same argument as `company()` below: the invoice's
     * own scope is the gate, and this is the order that raised it.
     *
     * An invoice always sits in its order's books, so this only ever matters
     * to a read that already crossed regions deliberately — and the one that
     * does is the tax filing. Scoped, the faktur pajak export loaded a null
     * order for every faktur outside the current region, found no priced
     * lines on it, and reported the faktur as *blocked for having no lines*.
     * A right-sounding refusal about the wrong thing is worse than a missing
     * row: somebody goes and looks at an order that is perfectly fine.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScope('region');
    }

    public function company(): BelongsTo
    {
        /*
         * Region-free: since orders split across warehouses, a document in
         * one region's books can belong to a customer homed in another.
         * Reading the customer through a document you can already see is
         * not a leak — the document's own scope is the gate.
         */
        return $this->belongsTo(Company::class)->withoutGlobalScope('region');
    }

    /**
     * Entries stamped with this invoice at the moment they were recorded.
     *
     * **Not what the invoice has been paid.** A transfer covering four
     * fakturs is one entry stamped with none of them, and part of a transfer
     * may settle this one; the money is counted from `allocations` below.
     * This relation remains because several screens legitimately ask "which
     * payment was keyed in against this faktur", and for the one-bill case
     * that is still exactly true.
     */
    public function paymentEntries(): HasMany
    {
        return $this->hasMany(PaymentEntry::class);
    }

    /** Every application of money to this invoice, reversals included. */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Sum of the append-only payment ledger for this invoice.
     *
     * Read from the allocations, which is where a payment says how much of
     * itself settles which bill. Reading `payment_entries.invoice_id` instead
     * would count a whole transfer against the first faktur it was pointed
     * at — the reading that used to leave one invoice at a negative balance
     * and the customer's others untouched.
     *
     * Reversals are negative rows in the same column, so this sum is the net
     * position and there is no flag to remember to exclude.
     */
    public function amountPaid(): int
    {
        return (int) $this->allocations()->sum('amount_rupiah');
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
