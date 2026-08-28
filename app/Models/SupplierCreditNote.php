<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's credit note: they owe us less, and nothing came off the shelf.
 *
 * `status`, `posted_at` and `posted_by` are not fillable. Only
 * SupplierCreditNoteIssuer moves them, because moving them is what writes the
 * journal — and a note that could be marked posted without one would take a
 * payable down in the register and leave it standing in the books.
 */
#[Fillable([
    'nomor', 'supplier_id', 'supplier_bill_id', 'tanggal', 'nomor_nota_supplier',
    'account_id', 'dasar_rupiah', 'ppn_rupiah', 'ada_faktur_pajak_retur',
    'total_rupiah', 'alasan', 'catatan', 'created_by',
])]
class SupplierCreditNote extends Model
{
    use HasFactory;
    use HasRegion;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'dasar_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'total_rupiah' => 'integer',
            'ada_faktur_pajak_retur' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    public function isDraft(): bool
    {
        return ! $this->isPosted();
    }

    /** Only posted notes reduce anything. */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }
}
