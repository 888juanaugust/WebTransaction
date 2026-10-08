<?php

namespace App\Models\Company;

use App\Domain\Documents\Accounts;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Models\Purchasing\Vendor;
use App\Models\Sales\Customer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * A receivable or payable carried in from before the data start date: an
 * open invoice that posts on the data start (trans_date) against Opening
 * Balance Equity, ages from its own invoice date (document_date), and is
 * settled by receipts or payments like any invoice.
 */
class OpeningBalance extends Model implements Postable
{
    use PostsToLedger;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trans_date' => 'date',
            'document_date' => 'date',
            'due_date' => 'date',
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'exchange_rate' => 'decimal:8',
            'fc_amount' => 'integer',
            'fc_paid_amount' => 'integer',
        ];
    }

    public function party(): MorphTo
    {
        return $this->morphTo();
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function isReceivable(): bool
    {
        return $this->party_type === (new Customer)->getMorphClass();
    }

    /** What settlement reads as the document's total. */
    protected function total(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->amount);
    }

    /** The date it ages from: the original invoice's. */
    public function agingDate(): string
    {
        return ($this->document_date ?? $this->trans_date)->toDateString();
    }

    public function postingNumber(): string
    {
        if (filled($this->number)) {
            return (string) $this->number;
        }

        return Str::limit('OB-'.($this->party?->number ?? $this->party_id).'-'.$this->id, 40, '');
    }

    public function postingDescription(): ?string
    {
        return $this->description ?: __('Opening balance of :name', ['name' => (string) $this->party?->name]);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $equity = Accounts::openingBalanceEquity();
        $memo = $this->postingNumber();
        if ($this->isReceivable()) {
            $party = $this->party instanceof Customer ? $this->party : null;
            $builder->debit(Accounts::receivable($party), (int) $this->amount, $memo);
            $builder->credit($equity, (int) $this->amount, $memo);
        } else {
            $party = $this->party instanceof Vendor ? $this->party : null;
            $builder->debit($equity, (int) $this->amount, $memo);
            $builder->credit(Accounts::payable($party), (int) $this->amount, $memo);
        }
    }
}
