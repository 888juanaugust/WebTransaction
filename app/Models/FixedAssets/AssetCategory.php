<?php

namespace App\Models\FixedAssets;

use App\Domain\Audit\RecordsActivity;
use App\Domain\FixedAssets\DepreciationMethod;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An asset category: the accounts, method and life a new asset of it starts with (A-02). */
class AssetCategory extends Model
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['depreciation_method' => DepreciationMethod::class, 'useful_life_months' => 'integer', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }
}
