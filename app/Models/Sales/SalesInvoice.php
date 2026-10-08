<?php

namespace App\Models\Sales;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Currency\Currencies;
use App\Domain\Documents\Accounts;
use App\Domain\Documents\DownPaymentShare;
use App\Domain\Documents\PricedDocument;
use App\Domain\Inventory\Costing\CostEngine;
use App\Domain\Inventory\GroupItems;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Domain\Settlement\SettlementService;
use App\Domain\Shared\Money;
use App\Models\Company\Branch;
use App\Models\Company\PaymentTerm;
use App\Models\Inventory\Item;
use App\Models\Inventory\StockMovement;
use App\Models\Tax\TaxInvoiceMail;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sales Invoice: the bill to the customer. Lines pulled from deliveries move
 * their cost from Goods Delivered, Not Invoiced to cost of goods sold; direct
 * lines take the goods out themselves. Revenue and VAT out per line, charges
 * to their accounts, down payments deducted gross, the rest receivable.
 */
class SalesInvoice extends Model implements Postable
{
    use PostsToLedger, PricedDocument;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'ship_date' => 'date', 'due_date' => 'date', 'nsfp_filed_at' => 'datetime', 'taxable' => 'boolean', 'inclusive_tax' => 'boolean', 'is_printed' => 'boolean',
            'subtotal' => 'integer', 'discount_amount' => 'integer', 'charges_total' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer',
            'down_payment_total' => 'integer', 'paid_amount' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function charges(): HasMany
    {
        return $this->hasMany(SalesInvoiceCharge::class)->orderBy('sort');
    }

    public function downPayments(): HasMany
    {
        return $this->hasMany(SalesInvoiceDownPayment::class);
    }

    /** The tax invoice mail log: each send asked for, and its outcome. */
    public function taxInvoiceMails(): HasMany
    {
        return $this->hasMany(TaxInvoiceMail::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function refreshTotal(): void
    {
        $this->refreshPricedTotal();
        $foreign = Currencies::isForeign($this->currency_id);
        if ($foreign) {
            // A down payment is deducted in the invoice's currency, at the down payment's own carrying value.
            foreach ($this->downPayments()->with('downPayment')->get() as $use) {
                $dpDoc = $use->downPayment;
                $use->forceFill(['amount' => (int) $dpDoc->fc_total !== 0 ? Money::mulDiv((int) $use->fc_amount, (int) $dpDoc->total, (int) $dpDoc->fc_total) : 0])->saveQuietly();
            }
        }
        foreach ($this->downPayments()->with('downPayment')->get() as $use) {
            if ($use->downPayment !== null) {
                $use->forceFill(DownPaymentShare::of((int) $use->amount, $use->downPayment))->saveQuietly();
            }
        }
        $dp = (int) $this->downPayments()->sum('amount');
        $this->forceFill([
            'fc_down_payment_total' => $foreign ? (int) $this->downPayments()->sum('fc_amount') : null,
            'down_payment_total' => $dp,
            'due_date' => $this->due_date ?? ($this->payment_term_id ? PaymentTerm::query()->find($this->payment_term_id)?->dueDate($this->trans_date) : $this->trans_date),
        ])->saveQuietly();
        foreach ($this->downPayments()->with('downPayment')->get() as $use) {
            $use->downPayment?->refreshStatus();
        }
        // Down payments (or a zero total) can leave nothing to pay: the payment status follows the totals too.
        app(SettlementService::class)->refresh($this);
        $this->forceFill(['status' => $this->payment_status === 'paid' ? 'processed' : 'pending'])->saveQuietly();
    }

    public function balance(): int
    {
        return $this->total - $this->down_payment_total - $this->paid_amount;
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $engine = app(CostEngine::class);
        $customer = $this->customer;
        $transit = Accounts::goodsDeliveredNotInvoiced();

        foreach ($this->lines()->with(['item.category', 'taxCode'])->get() as $line) {
            $net = $line->netAmount();
            $builder->credit(Accounts::sales($line->item, $customer), $net, $line->memo, tags: Tags::of($line));
            if ((int) $line->tax_amount > 0) {
                $builder->credit(Accounts::vatOut($line->taxCode), (int) $line->tax_amount, 'VAT out', tags: Tags::of($line));
            }

            $pieces = GroupItems::explode($line->item, (string) $line->base_quantity);
            if ($pieces === []) {
                continue;
            }
            if ($line->source_line_type === 'delivery_line' && $line->source_line_id) {
                // Cost moves from in-transit to cost of goods sold, in proportion to what this invoice takes of the delivery line,
                // per item the delivery moved (a group item's components each carry their own cost of sales account).
                $deliveryLine = DeliveryLine::query()->find($line->source_line_id);
                if ($deliveryLine === null || BigDecimal::of((string) $deliveryLine->base_quantity)->isZero()) {
                    continue;
                }
                $costs = StockMovement::query()->active()->where('source_line_type', 'delivery_line')->where('source_line_id', $deliveryLine->id)
                    ->groupBy('item_id')->orderBy('item_id')->selectRaw('item_id, sum(total_cost) as cost')->pluck('cost', 'item_id');
                foreach ($costs as $itemId => $cost) {
                    $share = BigDecimal::of((int) $cost)->multipliedBy((string) $line->base_quantity)->dividedBy((string) $deliveryLine->base_quantity, 0, RoundingMode::HalfUp)->toInt();
                    $moved = (int) $itemId === $line->item_id ? $line->item : Item::query()->with('category')->find($itemId);
                    $builder->debit(Accounts::costOfSales($moved, $customer), $share, $line->memo, tags: Tags::of($line));
                    $builder->credit($transit, $share, $line->memo, tags: Tags::of($line));
                }
            } else {
                foreach ($pieces as $piece) {
                    $cost = $engine->issueCost($piece['item']->id, $line->warehouse_id, $this->trans_date, $piece['base_quantity']);
                    $builder->stock(['item_id' => $piece['item']->id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::OUT, 'base_quantity' => $piece['base_quantity'],
                        'unit_cost' => $cost['unit_cost'], 'total_cost' => $cost['total_cost'], 'source_line_type' => 'sales_invoice_line', 'source_line_id' => $line->id]);
                    $builder->debit(Accounts::costOfSales($piece['item'], $customer), $cost['total_cost'], $line->memo, tags: Tags::of($line));
                    $builder->credit(Accounts::inventory($piece['item']), $cost['total_cost'], $line->memo, tags: Tags::of($line));
                }
            }
        }

        foreach ($this->charges as $charge) {
            $builder->credit($charge->account_id, (int) $charge->amount, $charge->description ?? 'Other charges', tags: Tags::of($charge));
        }

        $dpTotal = 0;
        foreach ($this->downPayments()->with('downPayment.taxCode')->get() as $use) {
            $dp = $use->downPayment;
            $applied = (int) $use->amount;
            $net = (int) $use->net_amount; // the stored split (DownPaymentShare)
            $builder->debit(Accounts::customerDownPayment($customer), $net, "Down payment {$dp->number} deducted");
            if ((int) $use->tax_amount !== 0) {
                $builder->debit(Accounts::vatOut($dp->taxCode), (int) $use->tax_amount, "VAT on down payment {$dp->number}");
            }
            $dpTotal += $applied;
        }

        $builder->debit(Accounts::receivable($customer), (int) $this->total - $dpTotal, $this->description ?? $this->number);
    }
}
