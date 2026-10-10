<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Domain\Audit\HasAuditReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fiscal year the Owner has closed: no month of it reopens. */
class FiscalYearClose extends Model implements HasAuditReference
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['fiscal_year_start' => 'date', 'fiscal_year_end' => 'date', 'closed_at' => 'datetime'];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function auditReference(): string
    {
        return $this->fiscal_year_start->format('Y').'/'.$this->fiscal_year_end->format('Y');
    }
}
