<?php

namespace App\Models\FixedAssets;

use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetChangeExpenditure extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function assetChange(): BelongsTo
    {
        return $this->belongsTo(AssetChange::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
