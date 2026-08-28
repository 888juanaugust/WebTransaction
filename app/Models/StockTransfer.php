<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods moving between our own warehouses.
 *
 * The paperwork that pairs an out with an in. Without it the two halves are
 * separate adjustments and nothing says they are the same cartons.
 */
#[Fillable([
    'nomor', 'from_warehouse_id', 'to_warehouse_id', 'tanggal', 'catatan', 'created_by',
])]
class StockTransfer extends Model
{
    use HasFactory;
    use HasRegion;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'total_value_rupiah' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('urutan')->orderBy('id');
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
}
