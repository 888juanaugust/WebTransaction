<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one entry.
 *
 * A line carries a debit or a credit, never both and never a negative. The
 * table has no timestamps because a line has no life of its own: it exists
 * exactly as long as its entry and is never touched again.
 */
#[Fillable([
    'journal_entry_id', 'account_id', 'debit_rupiah', 'kredit_rupiah', 'memo',
    'company_id', 'supplier_id', 'urutan',
])]
class JournalLine extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'debit_rupiah' => 'integer',
            'kredit_rupiah' => 'integer',
            'urutan' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isDebit(): bool
    {
        return $this->debit_rupiah > 0;
    }

    /** Signed, for arithmetic. Reports should use the account's normal balance. */
    public function signedAmount(): int
    {
        return (int) $this->debit_rupiah - (int) $this->kredit_rupiah;
    }
}
