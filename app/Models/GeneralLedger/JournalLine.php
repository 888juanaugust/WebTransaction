<?php

namespace App\Models\GeneralLedger;

use App\Models\CashBank\BankReconciliationItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One side of a journal entry. Append-only. */
class JournalLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'debit' => 'integer', 'credit' => 'integer'];
    }

    /** Lines of active postings: the only ones a balance counts. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereExists(fn ($q) => $q
            ->selectRaw('1')
            ->from('postings')
            ->whereColumn('postings.id', 'journal_lines.posting_id')
            ->whereNull('postings.superseded_at'));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }

    /** Set once the bank agreed with this line in a reconciliation. */
    public function reconciliationItem(): HasOne
    {
        return $this->hasOne(BankReconciliationItem::class);
    }
}
