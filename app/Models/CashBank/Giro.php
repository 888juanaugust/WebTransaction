<?php

namespace App\Models\CashBank;

use App\Domain\Documents\Accounts;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\Posting;
use App\Models\Settlement\PaymentAllocation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A giro (post-dated cheque) received on a receipt or issued on a payment
 * (K-06). Outstanding, it sits in giros receivable or payable; cleared, it
 * posts the bank leg on the clearing date; bounced, its document is withdrawn.
 */
class Giro extends Model implements Postable
{
    use PostsToLedger;

    public const IN = 'in';

    public const OUT = 'out';

    public const OUTSTANDING = 'outstanding';

    public const CLEARED = 'cleared';

    public const BOUNCED = 'bounced';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'due_date' => 'date', 'settled_on' => 'date', 'amount' => 'integer'];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::OUTSTANDING);
    }

    public function isOutstanding(): bool
    {
        return $this->status === self::OUTSTANDING;
    }

    public function isCleared(): bool
    {
        return $this->status === self::CLEARED;
    }

    public function isBounced(): bool
    {
        return $this->status === self::BOUNCED;
    }

    public function isIncoming(): bool
    {
        return $this->direction === self::IN;
    }

    public function postingDate(): CarbonInterface
    {
        return CarbonImmutable::parse($this->settled_on ?? $this->trans_date);
    }

    public function postingNumber(): string
    {
        return 'Giro '.$this->number;
    }

    public function postingDescription(): ?string
    {
        return ($this->isIncoming() ? 'Giro received from ' : 'Giro issued to ').($this->party_name ?: '—');
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        if ($this->isBounced()) {
            $this->buildBounce($builder);

            return;
        }
        if (! $this->isCleared()) {
            return; // an outstanding giro holds no journal of its own
        }
        $memo = "Giro {$this->number} cleared";
        if ($this->isIncoming()) {
            $builder->debit($this->bank_account_id, (int) $this->amount, $memo);
            $builder->credit(Accounts::giroReceivable(), (int) $this->amount, $memo);
        } else {
            $builder->debit(Accounts::giroPayable(), (int) $this->amount, $memo);
            $builder->credit($this->bank_account_id, (int) $this->amount, $memo);
        }
    }

    /**
     * The bank refused the giro: what its receipt or payment posted is reversed on the bounce date (the original
     * stays in its own period), and its settlements are taken back so what it settled is open again from that day.
     */
    private function buildBounce(PostingBuilder $builder): void
    {
        $source = $this->source;
        $posting = $source instanceof Postable ? Posting::active()->where('posting_key', $source->postingKey())->with('journalLines')->first() : null;
        if ($posting === null) {
            return;
        }
        $memo = "Giro {$this->number} bounced";
        foreach ($posting->journalLines as $line) {
            $builder->signed((int) $line->account_id, (int) $line->credit - (int) $line->debit, $memo, $line->branch_id, new Tags($line->department_id, $line->project_id));
        }
        foreach (PaymentAllocation::query()->where('posting_id', $posting->id)->orderBy('sort')->get() as $allocation) {
            $builder->allocate([
                'receivable_type' => $allocation->receivable_type,
                'receivable_id' => $allocation->receivable_id,
                'amount' => -(int) $allocation->amount,
                'discount' => -(int) $allocation->discount,
                'discount_account_id' => $allocation->discount_account_id,
            ]);
        }
    }
}
