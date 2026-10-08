<?php

namespace App\Models\FixedAssets;

use App\Models\Company\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An address where assets sit. */
class AssetLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'location_id');
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::query()->active()->orderBy('name')->pluck('name', 'id')->all();
    }
}
