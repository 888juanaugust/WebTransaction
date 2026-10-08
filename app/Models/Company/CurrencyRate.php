<?php

namespace App\Models\Company;

use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A currency's rate from a date: base currency per one unit, and the tax office's rate for VAT when it differs. */
class CurrencyRate extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['valid_from' => 'date'];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function auditReference(): string
    {
        return ($this->currency?->code ?? '').' '.$this->valid_from?->toDateString();
    }
}
