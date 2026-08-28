<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A month that has been closed.
 *
 * There is no `status` column and no row for an open month: a period is closed
 * if and only if it has a row. Storing "open" would mean two places could
 * disagree about a month nobody has touched, and the first time they did the
 * ledger would either refuse a legitimate entry or accept a back-dated one.
 */
#[Fillable(['tahun', 'bulan', 'closed_by', 'closed_at', 'catatan', 'closing_entry_id'])]
class AccountingPeriod extends Model
{
    use HasFactory;
    use HasRegion;

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'closing_entry_id');
    }

    public function start(): Carbon
    {
        return Carbon::create($this->tahun, $this->bulan, 1)->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->start()->endOfMonth()->startOfDay();
    }

    public function label(): string
    {
        return $this->start()->translatedFormat('F Y');
    }

    /** December closes the year as well as the month. */
    public function isYearEnd(): bool
    {
        return $this->bulan === 12;
    }
}
