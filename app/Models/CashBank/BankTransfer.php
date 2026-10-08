<?php

namespace App\Models\CashBank;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bank Transfer: money from one cash/bank account to another, fees charged to either side (K-04). */
class BankTransfer extends Model implements Postable
{
    use PostsToLedger;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'amount' => 'integer', 'fees_total' => 'integer', 'is_printed' => 'boolean'];
    }

    /** The posting layer reads lines(); a transfer's lines are its fees. */
    public function lines(): HasMany
    {
        return $this->fees();
    }

    public function fees(): HasMany
    {
        return $this->hasMany(BankTransferFee::class)->orderBy('sort');
    }

    public function fromBankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_bank_account_id');
    }

    public function toBankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_bank_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function refreshTotal(): void
    {
        $this->forceFill(['fees_total' => (int) $this->fees()->sum('amount')])->saveQuietly();
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $memo = $this->description ?: "Transfer {$this->number}";
        $builder->debit($this->to_bank_account_id, (int) $this->amount, $memo);
        $builder->credit($this->from_bank_account_id, (int) $this->amount, $memo);
        foreach ($this->fees as $fee) {
            $builder->debit($fee->account_id, (int) $fee->amount, $fee->memo ?: 'Transfer fee');
            $builder->credit($fee->charged_to === 'to' ? $this->to_bank_account_id : $this->from_bank_account_id, (int) $fee->amount, $fee->memo ?: 'Transfer fee');
        }
    }
}
