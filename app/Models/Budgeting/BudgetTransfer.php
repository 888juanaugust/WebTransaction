<?php

namespace App\Models\Budgeting;

use App\Domain\Posting\Contracts\AppliesEffects;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Budget moved from one account and month to another within a year (G-08); no journal, the budget lines follow. */
class BudgetTransfer extends Model implements AppliesEffects
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'year' => 'integer', 'from_month' => 'integer', 'to_month' => 'integer', 'amount' => 'integer'];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function applyEffects(): void
    {
        $this->move($this->from_month, $this->from_account_id, -(int) $this->amount);
        $this->move($this->to_month, $this->to_account_id, (int) $this->amount);
    }

    public function revertEffects(): void
    {
        $this->move($this->from_month, $this->from_account_id, (int) $this->amount);
        $this->move($this->to_month, $this->to_account_id, -(int) $this->amount);
    }

    private function move(int $month, int $accountId, int $delta): void
    {
        $budget = Budget::query()->firstOrCreate(['year' => $this->year, 'month' => $month, 'scope' => $this->scope ?? 'general'], ['created_by' => $this->created_by]);
        $line = $budget->lines()->firstOrCreate(['account_id' => $accountId], ['sort' => $budget->lines()->count(), 'amount' => 0]);
        $line->update(['amount' => (int) $line->amount + $delta]);
    }
}
