<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kode', 'nama', 'discount_bps', 'aktif', 'catatan'])]
class PriceTier extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'discount_bps' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceTierItem::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
