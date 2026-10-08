<?php

namespace App\Models\Sales;

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
 * Sales Receipt: money in from a customer against its open invoices and down
 * payments, credit notes applied when "use credit" is on, a discount or
 * write-off per line. Each line is an allocation; an invoice is paid only
 * through these.
 */
class SalesReceipt extends Model implements GiroSource, Postable
{
    use PostsToLedger;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'cheque_date' => 'date', 'amount' => 'integer', 'use_credit' => 'boolean', 'payment_method' => PaymentMethod::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReceiptLine::class)->orderBy('sort');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
            $paid = app(ForeignPayments::class)->prepare($this, 'receivable');
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

        return new GiroDetails(Giro::IN, (string) ($this->cheque_no ?: $this->number), $this->cheque_date?->toDateString(), (int) $this->amount, $this->customer?->name, $this->customer_id);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $foreignPayments = app(ForeignPayments::class);
        $foreign = Currencies::isForeign($this->currency_id);
        $bankCurrency = $foreignPayments->assertBank($this, (int) $this->bank_account_id);
        if ($foreign && $this->payment_method?->isCheque()) {
            throw new RuntimeException(__('A giro in a foreign currency is not supported; record the receipt when the money arrives.'));
        }
        $amounts = $foreign ? $foreignPayments->amounts($this, true, $bankCurrency, (int) $this->bank_account_id, $this->postingDate()) : null;
        $receivable = Accounts::receivable($this->customer);
        $received = 0;
        foreach ($this->lines()->with('receivable')->get() as $line) {
            $foreignPayments->assertSameCurrency($this, $line->receivable);
            $amount = (int) $line->amount;
            $discount = (int) $line->discount;
            if ($line->receivable !== null) {
                app(SettlementLimit::class)->assertParty($line->receivable, $this->customer);
                app(SettlementLimit::class)->assertLine($line->receivable, $amount, $discount, $foreign ? (int) $line->fc_amount : null, $foreign ? (int) $line->fc_discount : null);
            }
            $builder->signed($receivable, -($amount + $discount), $line->receivable?->number);
            if ($discount !== 0) {
                $builder->signed($line->discount_account_id ?? Accounts::salesDiscount($this->customer), $discount, 'Settlement discount');
            }
            $builder->allocate([
                'receivable_type' => $line->receivable_type,
                'receivable_id' => $line->receivable_id,
                'amount' => $amount,
                'discount' => $discount,
                'discount_account_id' => $line->discount_account_id,
            ] + ($foreign ? ['fc_amount' => (int) $line->fc_amount, 'fc_discount' => (int) $line->fc_discount, 'fx_difference' => $amounts['differences'][$line->id] ?? 0] : []));
            $received += $amount;
        }
        $debit = $this->giroDetails() !== null ? Accounts::giroReceivable() : $this->bank_account_id;
        $memo = $this->description ?? "Receipt from {$this->customer->name}";
        if ($amounts === null) {
            $builder->debit($debit, $received, $memo);

            return;
        }
        $builder->debit($debit, $amounts['base'], $memo, foreign: $bankCurrency !== null ? new ForeignAmount($bankCurrency, $amounts['foreign']) : null);
        $foreignPayments->postDifference($builder, array_sum($amounts['differences']), __('Exchange difference'));
    }
}
