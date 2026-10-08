<?php

namespace App\Models\FixedAssets;

use App\Domain\Posting\Contracts\AppliesEffects;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Assets moved from one location to another; no journal, the assets' location follows. */
class AssetTransfer extends Model implements AppliesEffects
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetTransferLine::class)->orderBy('sort');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'to_location_id');
    }

    public function applyEffects(): void
    {
        FixedAsset::query()->whereIn('id', $this->lines()->pluck('fixed_asset_id'))->update(['location_id' => $this->to_location_id]);
    }

    public function revertEffects(): void
    {
        FixedAsset::query()->whereIn('id', $this->lines()->pluck('fixed_asset_id'))->update(['location_id' => $this->from_location_id]);
    }
}
