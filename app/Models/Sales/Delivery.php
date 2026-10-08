<?php

namespace App\Models\Sales;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Documents\Accounts;
use App\Domain\Documents\PricedDocument;
use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Inventory\GroupItems;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\Company\Branch;
use App\Models\Inventory\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Delivery Order: goods out to the customer, pulled from orders, before the
 * invoice. Stock goes out at its average cost into Goods Delivered, Not
 * Invoiced; the invoice turns that into cost of goods sold.
 */
class Delivery extends Model implements Postable
{
    use PostsToLedger, PricedDocument;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'ship_date' => 'date', 'taxable' => 'boolean', 'inclusive_tax' => 'boolean', 'is_printed' => 'boolean',
            'subtotal' => 'integer', 'discount_amount' => 'integer', 'charges_total' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $engine = app(CostEngine::class);
        $transit = Accounts::goodsDeliveredNotInvoiced();
        foreach ($this->lines()->with('item.category')->get() as $line) {
            // A group item ships its components: one movement each, sourced to this line.
            foreach (GroupItems::explode($line->item, (string) $line->base_quantity) as $piece) {
                $cost = $engine->issueCost($piece['item']->id, $line->warehouse_id, $this->trans_date, $piece['base_quantity']);
                $builder->stock(['item_id' => $piece['item']->id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::OUT, 'base_quantity' => $piece['base_quantity'],
                    'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'], 'source_line_type' => 'delivery_line', 'source_line_id' => $line->id]);
                $builder->debit($transit, $cost['total_cost'], $line->memo, tags: Tags::of($line));
                $builder->credit(Accounts::inventory($piece['item']), $cost['total_cost'], $line->memo, tags: Tags::of($line));
            }
        }
    }
}
