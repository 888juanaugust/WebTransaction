<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period that was closed and then opened again.
 *
 * Append-only, like everything else here. Reopening the same month twice
 * writes two rows, because "how many times has August been reopened, and who
 * by" is exactly the question this exists to answer.
 */
#[Fillable(['tahun', 'bulan', 'reopened_by', 'alasan', 'reversal_entry_id'])]
class AccountingPeriodReopening extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['tahun' => 'integer', 'bulan' => 'integer'];
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function label(): string
    {
        return Carbon::create($this->tahun, $this->bulan, 1)->translatedFormat('F Y');
    }
}
