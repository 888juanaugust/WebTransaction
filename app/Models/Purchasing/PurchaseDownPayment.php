<?php

namespace App\Models\Purchasing;

use App\Domain\Currency\Currencies;
use App\Domain\Currency\ForeignTotals;
use App\Domain\Documents\Accounts;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Tax\TaxCalculator;
use App\Models\Company\Branch;
use App\Models\Company\PaymentTerm;
use App\Models\Company\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purchase Down Payment: money promised to a vendor before the invoice. A
 * payable itself (paid through Purchase Payments), later deducted on the
 * invoice it was for.
 */
class PurchaseDownPayment extends Model implements Postable
{
    use PostsToLedger;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'due_date' => 'date', 'taxable' => 'boolean', 'inclusive_tax' => 'boolean', 'is_printed' => 'boolean',
            'amount' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'total' => 'integer', 'paid_amount' => 'integer', 'used_amount' => 'integer'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function refreshTotal(): void
    {
        if (Currencies::isForeign($this->currency_id)) {
            ForeignTotals::refreshDownPayment($this);
            $this->forceFill(['due_date' => $this->due_date ?? ($this->payment_term_id ? PaymentTerm::query()->find($this->payment_term_id)?->dueDate($this->trans_date) : $this->trans_date)])->saveQuietly();
            $this->refreshStatus();

            return;
        }
        $code = $this->taxable ? $this->taxCode : null;
        $result = TaxCalculator::forLine((int) $this->amount, $code, (bool) $this->inclusive_tax);
        $this->forceFill([
            'subtotal' => $result->base,
            'dpp_total' => $result->dpp,
            'tax_total' => $result->tax,
            'total' => $result->gross,
            'due_date' => $this->due_date ?? ($this->payment_term_id ? PaymentTerm::query()->find($this->payment_term_id)?->dueDate($this->trans_date) : $this->trans_date),
            // in the base currency: no foreign amounts (a down payment moved back from a foreign currency loses them)
            'exchange_rate' => 1, 'tax_exchange_rate' => null, 'fc_amount' => null, 'fc_subtotal' => null, 'fc_tax_total' => null, 'fc_total' => null,
        ])->saveQuietly();
        $this->refreshStatus();
    }

    /** Pending until an invoice has used all of it. */
    public function refreshStatus(): void
    {
        $used = (int) PurchaseInvoiceDownPayment::query()->where('purchase_down_payment_id', $this->id)->sum('amount');
        // A foreign down payment is used up by its own currency's amount.
        $foreign = Currencies::isForeign($this->currency_id);
        $usedFc = $foreign ? (int) PurchaseInvoiceDownPayment::query()->where('purchase_down_payment_id', $this->id)->sum('fc_amount') : null;
        [$of, $by] = $foreign ? [(int) $this->fc_total, $usedFc] : [(int) $this->total, $used];
        $this->forceFill(['used_amount' => $used, 'fc_used_amount' => $usedFc, 'status' => $by >= $of && $of > 0 ? 'processed' : ($by > 0 ? 'partial' : 'pending')])->saveQuietly();
    }

    public function remaining(): int
    {
        return $this->total - $this->used_amount;
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $builder->debit(Accounts::vendorDownPayment($this->vendor), (int) $this->subtotal, $this->description);
        if ((int) $this->tax_total > 0) {
            $builder->debit(Accounts::vatIn($this->taxCode), (int) $this->tax_total, 'VAT on down payment');
        }
        $builder->credit(Accounts::payable($this->vendor), (int) $this->total, $this->description);
    }
}
