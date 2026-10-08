<?php

namespace App\Models\Tax;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaxFilingDocument extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dpp' => 'integer', 'tax' => 'integer'];
    }

    public function filing(): BelongsTo
    {
        return $this->belongsTo(TaxFiling::class, 'tax_filing_id');
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }
}
