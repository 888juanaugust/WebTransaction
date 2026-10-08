<?php

namespace App\Models\Inventory;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventory Adjustment: quantity in or out, or a value correction, per item
 * per warehouse, booked to an adjustment account. Also the opening stock.
 */
class InventoryAdjustment extends Model implements Postable
{
    use PostsToLedger;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'is_opening' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentLine::class)->orderBy('sort');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $engine = app(CostEngine::class);
        $prefs = app(Preferensi::class);
        $defaultAdjustment = $this->is_opening
            ? (Account::query()->where('no', '3300')->value('id') ?? $prefs->get(PreferensiKey::RoundingAccount))
            : (Account::query()->where('no', '5200')->value('id') ?? $prefs->get(PreferensiKey::CostOfSalesAccount));

        foreach ($this->lines()->with(['item.category'])->get() as $line) {
            $inventoryAccount = $line->item->accountFor('inventory')?->id ?? $prefs->get(PreferensiKey::InventoryAccount);
            $adjustmentAccount = $line->adjustment_account_id ?? $defaultAdjustment;
            $qty = BigDecimal::of((string) $line->base_quantity);

            if ($line->adjustment_type === InventoryAdjustmentLine::VALUE) {
                $delta = (int) $line->total_cost;
                $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::IN, 'base_quantity' => '0', 'unit_cost' => 0, 'total_cost' => $delta, 'source_line_type' => 'inventory_adjustment_line', 'source_line_id' => $line->id]);
                $builder->signed($inventoryAccount, $delta, $line->memo ?? 'Value adjustment', tags: Tags::of($line));
                $builder->signed($adjustmentAccount, -$delta, $line->memo ?? 'Value adjustment', tags: Tags::of($line));

                continue;
            }

            if ($qty->isPositive()) {
                $total = (int) $line->total_cost ?: CostEngine::money(BigDecimal::of((string) $line->unit_cost)->multipliedBy($qty)->toScale(0, RoundingMode::HalfUp)->toInt());
                $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::IN, 'base_quantity' => (string) $qty, 'unit_cost' => (string) $line->unit_cost, 'total_cost' => $total, 'source_line_type' => 'inventory_adjustment_line', 'source_line_id' => $line->id]);
                $builder->debit($inventoryAccount, $total, $line->memo, tags: Tags::of($line));
                $builder->credit($adjustmentAccount, $total, $line->memo, tags: Tags::of($line));
            } elseif ($qty->isNegative()) {
                $out = (string) $qty->abs();
                $cost = $engine->issueCost($line->item_id, $line->warehouse_id, $this->trans_date, $out);
                $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::OUT, 'base_quantity' => $out, 'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'], 'source_line_type' => 'inventory_adjustment_line', 'source_line_id' => $line->id]);
                $builder->debit($adjustmentAccount, $cost['total_cost'], $line->memo, tags: Tags::of($line));
                $builder->credit($inventoryAccount, $cost['total_cost'], $line->memo, tags: Tags::of($line));
            }
        }
    }
}
