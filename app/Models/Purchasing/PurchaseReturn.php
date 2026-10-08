<?php

namespace App\Models\Purchasing;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Documents\Accounts;
use App\Domain\Documents\PricedDocument;
use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\Company\Branch;
use App\Models\Inventory\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Purchase Return: goods back to the vendor. Stock goes out at its average
 * cost; the vendor owes a debit note for the price (and VAT) agreed, and the
 * gap between price and cost goes to inventory adjustments. A return from a
 * receipt not yet invoiced reduces Goods Received, Not Invoiced instead of
 * the payable. The debit note is a credit used in a purchase payment.
 */
class PurchaseReturn extends Model implements Postable
{
    use PostsToLedger, PricedDocument;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'taxable' => 'boolean', 'inclusive_tax' => 'boolean', 'is_printed' => 'boolean',
            'subtotal' => 'integer', 'discount_amount' => 'integer', 'charges_total' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer', 'paid_amount' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PurchaseReturnCharge::class)->orderBy('sort');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function refreshTotal(): void
    {
        $this->refreshPricedTotal();
        $this->forceFill(['status' => $this->payment_status === 'paid' ? 'processed' : 'pending'])->saveQuietly();
    }

    protected static function booted(): void
    {
        // A goods receipt is booked at its net value; VAT in comes with the invoice. A return against a receipt
        // therefore carries no VAT either: it takes back the net value from goods received not invoiced.
        static::saving(function (self $return): void {
            if ($return->return_type === 'receipt') {
                $return->taxable = false;
            }
        });
    }

    public function isCredit(): bool
    {
        return true;
    }

    /** As a credit against the vendor, the return's balance is negative. */
    public function balance(): int
    {
        return -($this->total - $this->paid_amount);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $engine = app(CostEngine::class);
        $owed = $this->return_type === 'receipt' ? Accounts::goodsReceivedNotInvoiced() : Accounts::payable($this->vendor);

        foreach ($this->lines()->with(['item.category', 'taxCode'])->get() as $line) {
            $net = $line->netAmount();
            if ($line->item->item_type->isStocked()) {
                $cost = $engine->issueCost($line->item_id, $line->warehouse_id, $this->trans_date, (string) $line->base_quantity);
                $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::OUT, 'base_quantity' => (string) $line->base_quantity,
                    'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'], 'source_line_type' => 'purchase_return_line', 'source_line_id' => $line->id]);
                $builder->credit(Accounts::inventory($line->item), $cost['total_cost'], $line->memo, tags: Tags::of($line));
                $builder->signed(Accounts::inventoryAdjustments(), $cost['total_cost'] - $net, 'Return price vs cost', tags: Tags::of($line));
            } else {
                $builder->credit(Accounts::purchaseExpense($line->item), $net, $line->memo, tags: Tags::of($line));
            }
            if ((int) $line->tax_amount > 0) {
                $builder->credit(Accounts::vatIn($line->taxCode), (int) $line->tax_amount, 'VAT in reversed', tags: Tags::of($line));
            }
        }
        foreach ($this->charges as $charge) {
            $builder->credit($charge->account_id, (int) $charge->amount, $charge->description ?? 'Other charges', tags: Tags::of($charge));
        }
        $builder->debit($owed, (int) $this->total, $this->description ?? "Return to {$this->vendor->name}");
    }
}
