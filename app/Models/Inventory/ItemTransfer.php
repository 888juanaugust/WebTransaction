<?php

namespace App\Models\Inventory;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Fulfilment\StatusDeriver;
use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Models\Company\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Item Transfer in two steps: a send moves the goods from the warehouse into
 * the In Transit warehouse; a receive, referencing the send, moves them from
 * In Transit into the destination. Costs travel with the goods.
 */
class ItemTransfer extends Model implements Postable
{
    use PostsToLedger;
    use RequiresApproval;

    public const SEND = 'send';

    public const RECEIVE = 'receive';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ItemTransferLine::class)->orderBy('sort');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function referenceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'reference_warehouse_id');
    }

    public function referenceTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_transfer_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(self::class, 'reference_transfer_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isSend(): bool
    {
        return $this->item_transfer_type === self::SEND;
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $transit = Warehouse::inTransit();
        $engine = app(CostEngine::class);
        [$from, $to] = $this->isSend() ? [$this->warehouse_id, $transit->id] : [$transit->id, $this->reference_warehouse_id];

        foreach ($this->lines as $line) {
            $qty = (string) $line->base_quantity;
            $cost = $engine->issueCost($line->item_id, $from, $this->trans_date, $qty);
            $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $from, 'direction' => StockMovement::OUT, 'base_quantity' => $qty, 'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'], 'source_line_type' => 'item_transfer_line', 'source_line_id' => $line->id]);
            $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $to, 'direction' => StockMovement::IN, 'base_quantity' => $qty, 'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'], 'source_line_type' => 'item_transfer_line', 'source_line_id' => $line->id]);
        }
    }

    /** The sent quantities not yet received, for the receive form. */
    public function remainingLines(): Collection
    {
        return $this->lines->filter(fn (ItemTransferLine $l) => bccomp_safe((string) $l->base_quantity, (string) $l->processed_quantity) > 0);
    }

    public function refreshStatus(): void
    {
        if (! $this->isSend()) {
            $this->forceFill(['status' => StatusDeriver::PROCESSED])->saveQuietly();

            return;
        }
        $this->forceFill(['status' => StatusDeriver::derive($this->lines()->get())])->saveQuietly();
    }
}
