<?php

namespace App\Models\Purchasing;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Currency\Currencies;
use App\Domain\Documents\Accounts;
use App\Domain\Documents\DownPaymentShare;
use App\Domain\Documents\PricedDocument;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Domain\Settlement\SettlementService;
use App\Domain\Shared\Money;
use App\Models\Company\Branch;
use App\Models\Company\PaymentTerm;
use App\Models\Inventory\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase Invoice: the vendor's bill. Lines pulled from receipts settle
 * Goods Received, Not Invoiced (a price difference goes to the stock value);
 * direct lines bring the goods in themselves. Other charges go to their
 * account or, when allocated to cost, into the stock value of the lines
 * (landed cost). Down payments deducted reduce what is owed.
 */
class PurchaseInvoice extends Model implements Postable
{
    use PostsToLedger, PricedDocument;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'ship_date' => 'date', 'due_date' => 'date', 'taxable' => 'boolean', 'inclusive_tax' => 'boolean', 'is_printed' => 'boolean',
            'subtotal' => 'integer', 'discount_amount' => 'integer', 'charges_total' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer',
            'down_payment_total' => 'integer', 'paid_amount' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class)->orderBy('sort')->chaperone(); // each line knows its document without a query
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceCharge::class)->orderBy('sort');
    }

    public function downPayments(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceDownPayment::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
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
        // An invoice is "processed" when paid; its lines are the end of the goods chain.
        $this->forceFill(['status' => $this->payment_status === 'paid' ? 'processed' : 'pending'])->saveQuietly();
    }

    public function balance(): int
    {
        return $this->total - $this->down_payment_total - $this->paid_amount;
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $lines = $this->lines()->with(['item.category', 'taxCode'])->get();
        $grni = Accounts::goodsReceivedNotInvoiced();

        // Charges allocated to cost are spread over the stocked lines by their net amounts.
        $landed = 0;
        foreach ($this->charges as $charge) {
            if ($charge->allocate_to_cost) {
                $landed += (int) $charge->amount;
            } else {
                $builder->debit($charge->account_id, (int) $charge->amount, $charge->description ?? 'Other charges', tags: Tags::of($charge));
            }
        }
        $stocked = $lines->filter(fn ($l) => $l->item->item_type->isStocked());
        $landedShares = $landed > 0 && $stocked->isNotEmpty()
            ? array_combine($stocked->pluck('id')->all(), Money::allocate($landed, $stocked->map(fn ($l) => max(1, $l->netAmount()))->all()))
            : [];

        foreach ($lines as $line) {
            $net = $line->netAmount();
            $landedShare = $landedShares[$line->id] ?? 0;
            $isStocked = $line->item->item_type->isStocked();
            $valueAccount = $isStocked ? Accounts::inventory($line->item) : Accounts::purchaseExpense($line->item);

            if ($line->source_line_type === 'goods_receipt_line' && $line->source_line_id) {
                $receiptLine = GoodsReceiptLine::query()->find($line->source_line_id);
                $received = $receiptLine ? $this->proportion($receiptLine->netAmount(), (string) $receiptLine->base_quantity, (string) $line->base_quantity) : $net;
                $builder->debit($grni, $received, $line->memo, tags: Tags::of($line));
                $difference = $net - $received + $landedShare;
                if ($difference !== 0) {
                    if ($isStocked) {
                        $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id ?? $receiptLine?->warehouse_id, 'direction' => StockMovement::IN, 'base_quantity' => '0', 'unit_cost' => 0, 'total_cost' => $difference, 'source_line_type' => 'purchase_invoice_line', 'source_line_id' => $line->id]);
                    }
                    $builder->signed($valueAccount, $difference, 'Price difference / landed cost', tags: Tags::of($line));
                }
            } else {
                $cost = $net + $landedShare;
                if ($isStocked) {
                    $builder->stock(['item_id' => $line->item_id, 'warehouse_id' => $line->warehouse_id, 'direction' => StockMovement::IN, 'base_quantity' => (string) $line->base_quantity,
                        'unit_cost' => (string) BigDecimal::of($cost)->dividedBy((string) $line->base_quantity, 4, RoundingMode::HalfUp), 'total_cost' => $cost,
                        'source_line_type' => 'purchase_invoice_line', 'source_line_id' => $line->id]);
                }
                $builder->debit($valueAccount, $cost, $line->memo, tags: Tags::of($line));
            }

            if ((int) $line->tax_amount > 0) {
                $builder->debit(Accounts::vatIn($line->taxCode), (int) $line->tax_amount, 'VAT in', tags: Tags::of($line));
            }
        }

        // A down payment is deducted gross: its net part leaves the down-payment account, its VAT part leaves VAT in.
        $dpTotal = 0;
        foreach ($this->downPayments()->with('downPayment.taxCode')->get() as $use) {
            $dp = $use->downPayment;
            $applied = (int) $use->amount;
            $net = (int) $use->net_amount; // the stored split (DownPaymentShare)
            $builder->credit(Accounts::vendorDownPayment($this->vendor), $net, "Down payment {$dp->number} deducted");
            if ((int) $use->tax_amount !== 0) {
                $builder->credit(Accounts::vatIn($dp->taxCode), (int) $use->tax_amount, "VAT on down payment {$dp->number}");
            }
            $dpTotal += $applied;
        }
        $builder->credit(Accounts::payable($this->vendor), (int) $this->total - $dpTotal, $this->description ?? $this->bill_number);
    }

    private function proportion(int $amount, string $whole, string $part): int
    {
        if (BigDecimal::of($whole)->isZero()) {
            return 0;
        }

        return BigDecimal::of($amount)->multipliedBy($part)->dividedBy($whole, 0, RoundingMode::HalfUp)->toInt();
    }
}
