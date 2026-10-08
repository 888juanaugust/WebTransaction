<?php

namespace App\Models\Sales;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Documents\Accounts;
use App\Domain\Documents\PricedDocument;
use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Inventory\GroupItems;
use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\Company\Branch;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Sales Return: goods back from the customer. Stock returns at the cost it
 * left with (the current average when unknown); the credit note reverses
 * revenue and VAT out and reduces what the customer owes, applied in a
 * receipt with "use credit".
 */
class SalesReturn extends Model implements Postable
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
        return $this->hasMany(SalesReturnLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SalesReturnCharge::class)->orderBy('sort');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isCredit(): bool
    {
        return true;
    }

    public function refreshTotal(): void
    {
        $this->refreshPricedTotal();
        $this->forceFill(['status' => $this->payment_status === 'paid' ? 'processed' : 'pending'])->saveQuietly();
    }

    public function balance(): int
    {
        return -($this->total - $this->paid_amount);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $engine = app(CostEngine::class);
        $customer = $this->customer;
        $prefs = app(Preferensi::class);
        // Preferences: the returned cost goes back to the item's cost of sales account, or to one fixed account.
        $chargeTo = $prefs->get(PreferensiKey::ReturnCostCharge) === 'account' && $prefs->get(PreferensiKey::ReturnCostAccount)
            ? (int) $prefs->get(PreferensiKey::ReturnCostAccount)
            : null;

        foreach ($this->lines()->with(['item.category', 'taxCode'])->get() as $line) {
            $net = $line->netAmount();
            $builder->debit(Accounts::salesReturn($line->item, $customer), $net, $line->memo, tags: Tags::of($line));
            if ((int) $line->tax_amount > 0) {
                $builder->debit(Accounts::vatOut($line->taxCode), (int) $line->tax_amount, 'VAT out reversed', tags: Tags::of($line));
            }
            // A group item comes back as its components, each at its return cost.
            foreach (GroupItems::explode($line->item, (string) $line->base_quantity) as $piece) {
                $item = $piece['item'];
                $unitCost = $this->returnCost($line, $item, $engine);
                $total = BigDecimal::of($unitCost)->multipliedBy($piece['base_quantity'])->toScale(0, RoundingMode::HalfUp)->toInt();
                $builder->stock(['item_id' => $item->id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::IN, 'base_quantity' => $piece['base_quantity'],
                    'unit_cost' => $unitCost, 'total_cost' => $total, 'source_line_type' => 'sales_return_line', 'source_line_id' => $line->id]);
                $builder->debit(Accounts::inventory($item), $total, $line->memo, tags: Tags::of($line));
                $builder->credit($chargeTo ?? Accounts::costOfSales($item, $customer), $total, $line->memo, tags: Tags::of($line));
            }
        }
        foreach ($this->charges as $charge) {
            $builder->debit($charge->account_id, (int) $charge->amount, $charge->description ?? 'Other charges', tags: Tags::of($charge));
        }
        $builder->credit(Accounts::receivable($customer), (int) $this->total, $this->description ?? "Return from {$customer->name}");
    }

    /**
     * The unit cost a returned item comes back at. Preferences choose the
     * cost it left with on the invoice (or delivery) the return points at,
     * or the item's last purchase price; either falls back to the moving
     * average on the return's date. With "update item cost on re-save" off,
     * saving the return again keeps the cost its goods first came back at.
     */
    private function returnCost(SalesReturnLine $line, Item $item, CostEngine $engine): string
    {
        $prefs = app(Preferensi::class);
        if (! $prefs->get(PreferensiKey::UpdateCostOnReturnResave)) {
            $earlier = StockMovement::query()->where('source_line_type', 'sales_return_line')->where('source_line_id', $line->id)->where('item_id', $item->id)->latest('id')->value('unit_cost');
            if ($earlier !== null) {
                return (string) $earlier;
            }
        }
        $cost = $prefs->get(PreferensiKey::CogsSource) === 'last_purchase_cost'
            ? ((int) $item->purchase_price > 0 ? (string) $item->purchase_price : null)
            : $this->costItLeftWith($line, $item->id);

        return $cost ?? $engine->costAt($item->id, $line->warehouse_id, $this->trans_date);
    }

    /** The unit cost an item left with, when the return points at an invoice whose line moved it (itself, or as a group's component). */
    private function costItLeftWith(SalesReturnLine $line, int $movedItemId): ?string
    {
        if ($this->source_type !== 'sales_invoice' || ! $this->source_id) {
            return null;
        }
        $invoiceLine = SalesInvoiceLine::query()->where('sales_invoice_id', $this->source_id)->where('item_id', $line->item_id)->first();
        if ($invoiceLine === null) {
            return null;
        }
        $movements = StockMovement::query()->active()->where('item_id', $movedItemId);
        $movement = (clone $movements)->where('source_line_type', 'sales_invoice_line')->where('source_line_id', $invoiceLine->id)->first()
            ?? ($invoiceLine->source_line_type === 'delivery_line' ? (clone $movements)->where('source_line_type', 'delivery_line')->where('source_line_id', $invoiceLine->source_line_id)->first() : null);

        return $movement ? (string) $movement->unit_cost : null;
    }
}
