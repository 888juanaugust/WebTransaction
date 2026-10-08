<?php

namespace App\Models\FixedAssets;

use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month of one asset's depreciation: expense against accumulated depreciation, on the month's last day. */
class AssetDepreciation extends Model implements Postable
{
    use PostsToLedger;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'amount' => 'integer', 'accumulated_after' => 'integer', 'book_value_after' => 'integer', 'created_at' => 'datetime'];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function postingDate(): CarbonInterface
    {
        return CarbonImmutable::parse($this->trans_date);
    }

    public function postingBranchId(): ?int
    {
        return $this->fixedAsset->branch_id;
    }

    public function postingNumber(): string
    {
        return "DEP {$this->fixedAsset->number} ".substr($this->period, 0, 4).'-'.substr($this->period, 4, 2);
    }

    public function postingDescription(): ?string
    {
        return "Depreciation of {$this->fixedAsset->name}, ".CarbonImmutable::parse($this->trans_date)->format('M Y');
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        $asset = $this->fixedAsset;
        $builder->debit($asset->depreciation_expense_account_id, (int) $this->amount, $this->postingDescription());
        $builder->credit($asset->accumulated_depreciation_account_id, (int) $this->amount, $this->postingDescription());
    }
}
