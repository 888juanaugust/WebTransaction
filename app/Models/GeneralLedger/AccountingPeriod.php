<?php

namespace App\Models\GeneralLedger;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AccountingPeriod extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'year' => 'integer', 'month' => 'integer'];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function label(): string
    {
        return Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y');
    }
}
