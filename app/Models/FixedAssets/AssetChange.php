<?php

namespace App\Models\FixedAssets;

use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\Posting\Contracts\AppliesEffects;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A change to an asset (A-05): new terms (method, salvage, life) that take
 * effect from the next depreciation, and added costs or a revaluation posted
 * to the asset account from the accounts on its lines.
 */
class AssetChange extends Model implements AppliesEffects, Postable
{
    use PostsToLedger;

    public const DATA = 'data';

    public const REVALUATION = 'revaluation';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trans_date' => 'date', 'amount' => 'integer', 'new_salvage_value' => 'integer', 'new_useful_life_months' => 'integer',
            'new_intangible' => 'boolean', 'new_fiscal' => 'boolean', 'new_depreciation_method' => DepreciationMethod::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->expenditures();
    }

    public function expenditures(): HasMany
    {
        return $this->hasMany(AssetChangeExpenditure::class)->orderBy('sort');
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function refreshTotal(): void
    {
        $this->forceFill(['amount' => (int) $this->expenditures()->sum('amount')])->saveQuietly();
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $asset = $this->fixedAsset;
        $account = $this->asset_account_id ?? $asset->asset_account_id;
        $total = 0;
        foreach ($this->expenditures as $line) {
            $builder->signed($line->account_id, -(int) $line->amount, $line->description ?: $this->description);
            $total += (int) $line->amount;
        }
        $builder->signed($account, $total, $this->description ?: "Change to {$asset->name}");
    }

    /** The asset takes the new terms; its cost cache follows the added amounts. */
    public function applyEffects(): void
    {
        $asset = $this->fixedAsset;
        $asset->forceFill(array_filter([
            'depreciation_method' => $this->new_depreciation_method,
            'salvage_value' => $this->new_salvage_value,
            'useful_life_months' => $this->new_useful_life_months,
            'intangible' => $this->new_intangible,
            'fiscal' => $this->new_fiscal,
            'asset_account_id' => $this->asset_account_id,
        ], fn ($v) => $v !== null))->saveQuietly();
        $asset->refreshTotal();
    }

    public function revertEffects(): void
    {
        $this->fixedAsset->refreshTotal();
    }
}
