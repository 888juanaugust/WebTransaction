<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods going back to a supplier. Draft until posted; posted forever after.
 *
 * The money columns are not fillable and neither is `status`. Both are settled
 * by PurchaseReturnPoster inside the transaction that writes the stock ledger —
 * the same rule the goods receipt follows, and for the same reason: the figure
 * on the document has to be the figure that went to the books.
 */
#[Fillable([
    'nomor', 'supplier_id', 'goods_receipt_id', 'warehouse_id', 'tanggal',
    'alasan', 'nomor_nota_kredit_supplier', 'created_by',
])]
class PurchaseReturn extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected $attributes = ['status' => self::STATUS_DRAFT];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nilai_ditagih_rupiah' => 'integer',
            'nilai_belum_ditagih_rupiah' => 'integer',
            'dpp_rupiah' => 'integer',
            'ppn_rupiah' => 'integer',
            'total_rupiah' => 'integer',
            'nilai_persediaan_rupiah' => 'integer',
            'selisih_rupiah' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class)->orderBy('urutan')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * `postedBy`, not `poster`.
     *
     * Named to match every other posted document that has a detail screen —
     * credit notes, transfers, opnames, landed costs, journals. The screen
     * asks for `postedBy.name`, and a relation named anything else silently
     * renders an em dash where a person's name belongs, on a document whose
     * whole point is that somebody signed for it.
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    /** Base units going back, across every line. */
    public function qtyReturned(): int
    {
        return (int) $this->lines()->sum('qty_base');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }
}
