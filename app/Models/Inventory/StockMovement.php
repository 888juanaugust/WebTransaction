<?php

namespace App\Models\Inventory;

use App\Models\GeneralLedger\Posting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One movement of the stock ledger. Append-only; counts while its posting is active. */
class StockMovement extends Model
{
    public const IN = 'in';

    public const OUT = 'out';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'base_quantity' => 'decimal:4', 'unit_cost' => 'decimal:4', 'total_cost' => 'integer'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereExists(fn ($q) => $q
            ->selectRaw('1')
            ->from('postings')
            ->whereColumn('postings.id', 'stock_movements.posting_id')
            ->whereNull('postings.superseded_at'));
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
