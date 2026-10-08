<?php

namespace App\Models\CashBank;

use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One movement on the bank's statement; signed amount, money in positive. */
class BankStatementLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'amount' => 'integer', 'balance' => 'integer'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function reconciliationItem(): HasOne
    {
        return $this->hasOne(BankReconciliationItem::class, 'bank_statement_line_id');
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereDoesntHave('reconciliationItem');
    }
}
