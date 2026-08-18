<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Banking\StatementDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something on the statement the books had never heard of.
 *
 * `journal_entry_id` is not fillable: it is written by BankReconciler when the
 * item posts, and an item without one would be a claim about the bank that
 * never reached the ledger.
 */
#[Fillable([
    'bank_reconciliation_id', 'tanggal', 'keterangan', 'account_id',
    'arah', 'amount_rupiah', 'created_by',
])]
class BankReconciliationItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'arah' => StatementDirection::class,
            'amount_rupiah' => 'integer',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Signed against the bank balance: in is positive, out is negative. */
    public function signedAmount(): int
    {
        return $this->arah->sign() * (int) $this->amount_rupiah;
    }
}
