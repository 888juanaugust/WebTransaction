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
 * A stock count, and what it found.
 *
 * The variance is the point. A shelf short of what the system says is a loss
 * that has to land somewhere, and the person who counted must not be the
 * person who signs it off — that separation is why `counted_by` and
 * `posted_by` are two columns rather than one.
 */
#[Fillable(['nomor', 'warehouse_id', 'tanggal', 'catatan', 'created_by', 'counted_by'])]
class StockOpname extends Model
{
    use HasFactory;
    use HasRegion;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'selisih_qty' => 'integer',
            'selisih_rupiah' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOpnameLine::class)->orderBy('urutan')->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
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

    /** Lines somebody has actually put a number against. */
    public function countedLines(): HasMany
    {
        return $this->lines()->whereNotNull('qty_counted');
    }
}
