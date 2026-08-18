<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tick: this journal line appeared on that statement.
 *
 * No `updated_at`, and nothing on it is editable. A tick is either there or it
 * is not — unticking deletes the row, which is the only correct shape for a
 * fact that is really a boolean about somebody else's document.
 */
#[Fillable(['bank_reconciliation_id', 'journal_line_id'])]
class BankReconciliationLine extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
