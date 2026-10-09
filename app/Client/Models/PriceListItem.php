<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Models\Inventory\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An item's price per base unit in one version of the price list. */
class PriceListItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'integer', 'qty_per_ctn' => 'integer', 'is_active' => 'boolean'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class, 'version_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
