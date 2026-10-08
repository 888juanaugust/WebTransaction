<?php

namespace App\Models\FixedAssets;

use App\Domain\Posting\Contracts\AppliesEffects;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Models\Company\Branch;
use App\Models\GeneralLedger\Account;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A disposal (A-04): the quantity disposed of takes its share of the cost and
 * accumulated depreciation off the books; what it fetched, less what it was
 * still worth, is the gain or loss.
 */
class AssetDisposal extends Model implements AppliesEffects, Postable
{
    use PostsToLedger;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'trans_date' => 'date', 'quantity' => 'decimal:4', 'selling_asset' => 'boolean',
            'proceeds' => 'integer', 'cost_removed' => 'integer', 'depreciation_removed' => 'integer', 'gain_loss' => 'integer',
        ];
    }

    /** No lines of its own; the posting layer still asks. */
    public function lines(): HasMany
    {
        return $this->hasMany(AssetTransferLine::class, 'asset_transfer_id')->whereRaw('1 = 0');
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function gainLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gain_loss_account_id');
    }

    public function proceedsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'proceeds_account_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'location_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The share of what is still held that this disposal takes; figures are fixed here, before posting. */
    public function refreshTotal(): void
    {
        $asset = $this->fixedAsset;
        $others = $asset->disposals()->whereKeyNot($this->getKey());
        $remainingQty = BigDecimal::of((string) $asset->quantity)->minus((string) ($others->sum('quantity') ?: '0'));
        $costRemaining = (int) $asset->cost - (int) $others->sum('cost_removed');
        $accumulated = (int) $asset->depreciations()->sum('amount') - (int) $others->sum('depreciation_removed');
        $share = $remainingQty->isZero() ? BigDecimal::zero() : BigDecimal::of((string) $this->quantity)->dividedBy($remainingQty, 12, RoundingMode::HalfUp);
        $wholeLot = $share->isGreaterThanOrEqualTo(1);

        $costRemoved = $wholeLot ? $costRemaining : $share->multipliedBy($costRemaining)->toScale(0, RoundingMode::HalfUp)->toInt();
        $depreciationRemoved = $wholeLot ? $accumulated : $share->multipliedBy($accumulated)->toScale(0, RoundingMode::HalfUp)->toInt();
        $proceeds = $this->selling_asset ? (int) $this->proceeds : 0;

        $this->forceFill([
            'proceeds' => $proceeds,
            'cost_removed' => $costRemoved,
            'depreciation_removed' => $depreciationRemoved,
            'gain_loss' => $proceeds - ($costRemoved - $depreciationRemoved),
        ])->saveQuietly();
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $asset = $this->fixedAsset;
        $memo = $this->description ?: "Disposal of {$asset->name}";
        $builder->debit($asset->accumulated_depreciation_account_id, (int) $this->depreciation_removed, $memo);
        if ($this->selling_asset && (int) $this->proceeds !== 0 && $this->proceeds_account_id) {
            $builder->debit($this->proceeds_account_id, (int) $this->proceeds, $memo);
        }
        $builder->credit($asset->asset_account_id, (int) $this->cost_removed, $memo);
        $builder->signed($this->gain_loss_account_id, -(int) $this->gain_loss, $memo); // a gain credits, a loss debits
    }

    public function applyEffects(): void
    {
        $asset = $this->fixedAsset;
        $gone = BigDecimal::of($asset->quantityRemaining())->isLessThanOrEqualTo(0);
        $asset->forceFill([
            'status' => $gone ? FixedAsset::DISPOSED : FixedAsset::ACTIVE,
            'disposed_on' => $gone ? $this->trans_date : null,
        ])->saveQuietly();
    }

    public function revertEffects(): void
    {
        $asset = $this->fixedAsset;
        $asset->forceFill(['status' => FixedAsset::ACTIVE, 'disposed_on' => null])->saveQuietly();
    }
}
