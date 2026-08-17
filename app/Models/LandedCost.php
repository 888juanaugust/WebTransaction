<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Purchasing\AllocationBasis;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One charge, spread over the goods it belongs to.
 *
 * The charge itself is a line on a supplier bill — the forwarder's invoice,
 * entered and owed like any other. This document does not create money; it
 * decides where money that already exists belongs.
 */
#[Fillable([
    'nomor', 'supplier_bill_line_id', 'tanggal', 'dasar', 'amount_rupiah',
    'catatan', 'created_by',
])]
class LandedCost extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'dasar' => AllocationBasis::class,
            'amount_rupiah' => 'integer',
            'ke_persediaan_rupiah' => 'integer',
            'ke_hpp_rupiah' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class)->orderBy('urutan')->orderBy('id');
    }

    public function supplierBillLine(): BelongsTo
    {
        return $this->belongsTo(SupplierBillLine::class);
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

    /** The receipts this charge was spread over, for a one-line summary. */
    public function receiptNumbers(): array
    {
        return $this->lines()
            ->with('goodsReceiptLine.goodsReceipt')
            ->get()
            ->map(fn (LandedCostLine $line) => $line->goodsReceiptLine?->goodsReceipt?->nomor)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
