<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Purchasing\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What we asked a supplier for. See PurchaseOrderStatus for the shape.
 */
#[Fillable([
    'nomor', 'supplier_id', 'warehouse_id', 'tanggal_po', 'tanggal_diharapkan',
    'referensi_supplier', 'catatan', 'created_by',
])]
class PurchaseOrder extends Model
{
    use HasFactory;

    /**
     * `status` is not fillable — only PurchaseOrderFlow may write it, the same
     * rule orders and goods receipts follow.
     */
    protected $attributes = ['status' => PurchaseOrderStatus::Draft->value];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'tanggal_po' => 'date',
            'tanggal_diharapkan' => 'date',
            'total_value_rupiah' => 'integer',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Who sent it to the supplier — the name that signs the printed order. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('urutan')->orderBy('id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    /** Every line delivered in full. */
    public function isFullyReceived(): bool
    {
        return $this->lines->every(fn (PurchaseOrderLine $line) => $line->outstandingQty() <= 0);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', PurchaseOrderStatus::Dikirim->value);
    }
}
