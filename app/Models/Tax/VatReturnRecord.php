<?php

namespace App\Models\Tax;

use App\Domain\Audit\HasAuditReference;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A VAT return saved for a period, numbered from the VAT return series, with the totals it reported. */
class VatReturnRecord extends Model implements HasAuditReference
{
    protected $table = 'vat_returns';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'until_date' => 'date', 'vat_out_base' => 'integer', 'vat_out' => 'integer', 'vat_in_base' => 'integer', 'vat_in' => 'integer', 'payable' => 'integer', 'document_count' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function auditReference(): string
    {
        return (string) $this->number;
    }
}
