<?php

namespace App\Models\GeneralLedger;

use App\Domain\Documents\Accounts;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's balance at the data start date, posted against Opening
 * Balance Equity so the books balance from day one.
 */
class AccountOpeningBalance extends Model implements Postable
{
    use PostsToLedger;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'amount' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function postingNumber(): string
    {
        return 'OPENING-'.$this->account->no;
    }

    public function postingDescription(): ?string
    {
        return "Opening balance of {$this->account->displayName()}";
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $equity = Accounts::openingBalanceEquity();

        // The amount is entered with the account's normal sign.
        $signed = $this->account->account_type->isDebitNormal() ? $this->amount : -$this->amount;
        $builder->signed($this->account_id, $signed, 'Opening balance');
        $builder->signed($equity, -$signed, 'Opening balance');
    }
}
