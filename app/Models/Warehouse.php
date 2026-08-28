<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Regions\HasRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['kode', 'nama', 'alamat', 'aktif'])]
class Warehouse extends Model
{
    use HasFactory;
    use HasRegion;

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
