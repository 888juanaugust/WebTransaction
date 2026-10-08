<?php

namespace App\Models\Purchasing;

use App\Domain\Approval\RequiresApproval;
use App\Domain\CashBank\Contracts\GiroSource;
use App\Domain\CashBank\GiroDetails;
use App\Domain\Currency\Currencies;
use App\Domain\Currency\ForeignAmount;
use App\Domain\Currency\ForeignPayments;
use App\Domain\Documents\Accounts;
use App\Domain\Documents\PaymentMethod;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Settlement\SettlementLimit;
use App\Models\CashBank\Giro;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use RuntimeException;

/**
 * Purchase Payment: money out of a bank account against one vendor's
 * invoices, down payments and returns (as credits), with a discount taken
 * per line. Each line is an allocation in the settlement ledger.
 */
class PurchasePayment extends Model implements GiroSource, Postable
{
    use PostsToLedger;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'cheque_date' => 'date', 'amount' => 'integer', 'payment_method' => PaymentMethod::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchasePaymentLine::class)->orderBy('sort');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function refreshTotal(): void
    {
        if (Currencies::isForeign($this->currency_id)) {
            $paid = app(ForeignPayments::class)->prepare($this, 'payable');
            $this->forceFill(['amount' => $paid, 'fc_amount' => (int) $this->lines()->sum('fc_amount')])->saveQuietly();

            return;
        }
        $this->forceFill(['amount' => (int) $this->lines()->sum('amount')])->saveQuietly();
    }

    public function giro(): MorphOne
    {
        return $this->morphOne(Giro::class, 'source');
    }

    public function giroDetails(): ?GiroDetails
    {
        if (! $this->payment_method?->isCheque()) {
            return null;
        }

        return new GiroDetails(Giro::OUT, (string) ($this->cheque_no ?: $this->number), $this->cheque_date?->toDateString(), (int) $this->amount, $this->vendor?->name, $this->vendor_id);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $foreignPayments = app(ForeignPayments::class);
        $foreign = Currencies::isForeign($this->currency_id);
        $bankCurrency = $foreignPayments->assertBank($this, (int) $this->bank_account_id);
        if ($foreign && $this->payment_method?->isCheque()) {
            throw new RuntimeException(__('A giro in a foreign currency is not supported; record the payment when the money leaves.'));
        }
        $amounts = $foreign ? $foreignPayments->amounts($this, false, $bankCurrency, (int) $this->bank_account_id, $this->postingDate()) : null;
        $payable = Accounts::payable($this->vendor);
        $paid = 0;
        foreach ($this->lines()->with('payable')->get() as $line) {
            $foreignPayments->assertSameCurrency($this, $line->payable);
            $amount = (int) $line->amount;
            $discount = (int) $line->discount;
            if ($line->payable !== null) {
                app(SettlementLimit::class)->assertParty($line->payable, $this->vendor);
                app(SettlementLimit::class)->assertLine($line->payable, $amount, $discount, $foreign ? (int) $line->fc_amount : null, $foreign ? (int) $line->fc_discount : null);
            }
            $builder->signed($payable, $amount + $discount, $line->payable?->number);
            if ($discount !== 0) {
                $builder->signed($line->discount_account_id ?? Accounts::purchaseDiscounts(), -$discount, 'Payment discount');
            }
            $builder->allocate([
                'receivable_type' => $line->payable_type,
                'receivable_id' => $line->payable_id,
                'amount' => $amount,
                'discount' => $discount,
                'discount_account_id' => $line->discount_account_id,
            ] + ($foreign ? ['fc_amount' => (int) $line->fc_amount, 'fc_discount' => (int) $line->fc_discount, 'fx_difference' => $amounts['differences'][$line->id] ?? 0] : []));
            $paid += $amount;
        }
        $credit = $this->giroDetails() !== null ? Accounts::giroPayable() : $this->bank_account_id;
        $memo = $this->description ?? "Payment to {$this->vendor->name}";
        if ($amounts === null) {
            $builder->credit($credit, $paid, $memo);

            return;
        }
        $builder->credit($credit, $amounts['base'], $memo, foreign: $bankCurrency !== null ? new ForeignAmount($bankCurrency, -$amounts['foreign']) : null);
        $foreignPayments->postDifference($builder, array_sum($amounts['differences']), __('Exchange difference'));
    }
}
