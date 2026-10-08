<?php

namespace App\Models\FixedAssets;

use App\Domain\FixedAssets\DepreciationMethod;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed asset (A-01): bought from the accounts on its expenditure lines,
 * depreciated monthly by its method over its life down to its salvage value,
 * changed, moved and disposed of by their own documents. Its acquisition is
 * its posting: the asset account against what paid for it.
 */
class FixedAsset extends Model implements Postable
{
    use PostsToLedger;

    public const ACTIVE = 'active';

    public const DISPOSED = 'disposed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trans_date' => 'date', 'usage_date' => 'date', 'disposed_on' => 'date',
            'intangible' => 'boolean', 'fiscal' => 'boolean',
            'depreciation_method' => DepreciationMethod::class,
            'quantity' => 'decimal:4', 'useful_life_months' => 'integer', 'salvage_value' => 'integer', 'cost' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** The posting layer reads lines(); an asset's lines are its expenditures. */
    public function lines(): HasMany
    {
        return $this->expenditures();
    }

    public function expenditures(): HasMany
    {
        return $this->hasMany(FixedAssetExpenditure::class)->orderBy('sort');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('period');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(AssetChange::class)->orderBy('trans_date');
    }

    public function disposals(): HasMany
    {
        return $this->hasMany(AssetDisposal::class)->orderBy('trans_date');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function fiscalCategory(): BelongsTo
    {
        return $this->belongsTo(FiscalAssetCategory::class, 'fiscal_asset_category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function postingDescription(): ?string
    {
        return "Acquisition of {$this->name}";
    }

    /** Cost = what was paid at acquisition plus every change's added cost. */
    public function refreshTotal(): void
    {
        $cost = (int) $this->expenditures()->sum('amount') + (int) $this->changes()->sum('amount');
        $this->forceFill(['cost' => $cost])->saveQuietly();
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $total = 0;
        foreach ($this->expenditures as $line) {
            $builder->credit($line->account_id, (int) $line->amount, $line->description ?: "Purchase of {$this->name}");
            $total += (int) $line->amount;
        }
        $builder->debit($this->asset_account_id, $total, "Purchase of {$this->name}");
    }

    // --- the figures every document of the asset works from ---------------

    public function costRemaining(): int
    {
        return (int) $this->cost - (int) $this->disposals()->sum('cost_removed');
    }

    public function accumulatedDepreciation(): int
    {
        return (int) $this->depreciations()->sum('amount') - (int) $this->disposals()->sum('depreciation_removed');
    }

    public function bookValue(): int
    {
        return $this->costRemaining() - $this->accumulatedDepreciation();
    }

    public function quantityRemaining(): string
    {
        return (string) BigDecimal::of((string) $this->quantity)->minus((string) ($this->disposals()->sum('quantity') ?: '0'))->toScale(4, RoundingMode::HalfUp);
    }

    /** The salvage value of the part still held. */
    public function salvageRemaining(): int
    {
        $quantity = BigDecimal::of((string) $this->quantity);
        if ($quantity->isZero()) {
            return 0;
        }

        return BigDecimal::of((string) $this->salvage_value)->multipliedBy($this->quantityRemaining())->dividedBy($quantity, 0, RoundingMode::HalfUp)->toInt();
    }

    public function depreciableRemaining(): int
    {
        return max(0, $this->costRemaining() - $this->salvageRemaining() - $this->accumulatedDepreciation());
    }

    public function isDisposed(): bool
    {
        return $this->status === self::DISPOSED;
    }
}
