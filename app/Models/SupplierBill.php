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
use Illuminate\Support\Facades\DB;

/**
 * What we owe a supplier. The mirror of Invoice, down to the three statuses.
 *
 * The money columns are not fillable: they are summed from the line snapshots
 * when the bill is posted and are never editable afterwards, by anybody. That
 * is the same rule the customer invoice follows, and for the same reason —
 * whoever pays must not be able to move the amount owed.
 */
#[Fillable([
    'nomor', 'supplier_id', 'purchase_order_id', 'nomor_faktur_supplier',
    'nomor_faktur_pajak', 'tanggal_faktur', 'due_date', 'catatan', 'created_by',
])]
class SupplierBill extends Model
{
    use HasFactory;
    use HasRegion;

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected function casts(): array
    {
        return [
            'tanggal_faktur' => 'date',
            'due_date' => 'date',
            'subtotal_rupiah' => 'integer',
            'discount_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'total_rupiah' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class)->orderBy('urutan')->orderBy('id');
    }

    public function paymentEntries(): HasMany
    {
        return $this->hasMany(SupplierPaymentEntry::class);
    }

    public function purchaseReturnLines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class);
    }

    /** Sum of the append-only ledger for this bill. */
    public function amountPaid(): int
    {
        return (int) $this->paymentEntries()->sum('amount_rupiah');
    }

    /**
     * Goods off this bill that went back to the supplier.
     *
     * Posted returns only — a draft is an intention, and letting one reduce a
     * bill would take an invoice out of the payment run on the strength of a
     * document nobody has agreed to. As on SupplierLedger, that filter is
     * currently unfalsifiable by test because a draft line carries zeroes; it
     * is stated anyway, for the same reason.
     *
     * The bill's own total is untouched by any of this, which is the control:
     * whoever pays cannot move the amount owed. What a return changes is what
     * is still *outstanding* on it.
     */
    public function amountReturned(): int
    {
        return (int) $this->purchaseReturnLines()
            ->join('purchase_returns', 'purchase_return_lines.purchase_return_id', '=', 'purchase_returns.id')
            ->where('purchase_returns.status', PurchaseReturn::STATUS_POSTED)
            ->sum(DB::raw('purchase_return_lines.nilai_ditagih_rupiah + purchase_return_lines.ppn_rupiah'));
    }

    public function amountOutstanding(): int
    {
        return $this->total_rupiah - $this->amountPaid() - $this->amountReturned();
    }

    /** Input VAT this bill carries — creditable only with a faktur pajak behind it. */
    public function isCreditableInput(): bool
    {
        return $this->ppn_rupiah > 0 && $this->nomor_faktur_pajak !== null;
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN)
            ->whereDate('due_date', '<', now()->toDateString());
    }
}
