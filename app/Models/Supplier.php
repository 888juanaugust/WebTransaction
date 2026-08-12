<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who we buy from. Thin on purpose — see the migration.
 */
#[Fillable([
    'kode', 'nama', 'nama_kontak', 'telepon', 'email', 'alamat', 'npwp', 'aktif', 'catatan',
])]
class Supplier extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
