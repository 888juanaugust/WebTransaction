<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'price_tier_id', 'kode', 'min_qty_base', 'harga', 'discount_bps',
    'effective_from', 'effective_until',
])]
class PriceTierItem extends Model
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

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(PriceTier::class);
    }
}
