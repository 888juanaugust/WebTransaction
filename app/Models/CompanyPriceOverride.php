<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'kode', 'min_qty_base', 'harga', 'discount_bps',
    'effective_from', 'effective_until', 'created_by', 'alasan',
])]
class CompanyPriceOverride extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'min_qty_base' => 'integer',
            'harga' => 'integer',
            'discount_bps' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
