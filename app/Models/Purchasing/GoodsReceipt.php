<?php

namespace App\Models\Purchasing;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Documents\Accounts;
use App\Domain\Documents\PricedDocument;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\Company\Branch;
use App\Models\Inventory\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods Receipt: goods in from a vendor, at the order's price, before the
 * invoice. Stock comes in; the books owe the vendor under Goods Received,
 * Not Invoiced until the invoice turns it into a payable.
 */
class GoodsReceipt extends Model implements Postable
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
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $grni = Accounts::goodsReceivedNotInvoiced();
        foreach ($this->lines()->with('item.category')->get() as $line) {
            $net = $line->netAmount();
            if ($line->item->item_type->isStocked()) {
                $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::IN, 'base_quantity' => (string) $line->base_quantity,
                    'unit_cost' => (string) BigDecimal::of($net)->dividedBy((string) $line->base_quantity, 4, RoundingMode::HalfUp), 'total_cost' => $net,
                    'source_line_type' => 'goods_receipt_line', 'source_line_id' => $line->id]);
                $builder->debit(Accounts::inventory($line->item), $net, $line->memo, tags: Tags::of($line));
            } else {
                $builder->debit(Accounts::purchaseExpense($line->item), $net, $line->memo, tags: Tags::of($line));
            }
            $builder->credit($grni, $net, $line->memo, tags: Tags::of($line));
        }
    }
}
