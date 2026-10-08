<?php

namespace App\Models\Tax;

use App\Models\Company\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One export file handed to the tax office's application. */
class TaxFiling extends Model
{
    public const UPDATED_AT = null;

    public const OUT = 'out';

    public const IN = 'in';

    public const CORETAX = 'coretax';

    public const LEGACY = 'legacy';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'date', 'to_date' => 'date', 'period_year' => 'integer', 'period_month' => 'integer', 'document_count' => 'integer', 'dpp_total' => 'integer', 'tax_total' => 'integer', 'created_at' => 'datetime'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TaxFilingDocument::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
