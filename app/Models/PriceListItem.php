<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version_id', 'kode', 'harga', 'qty_per_ctn', 'aktif'])]
class PriceListItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'harga' => 'integer',
            'qty_per_ctn' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class, 'version_id');
    }
}
