<?php

namespace App\Models\GeneralLedger;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Posting\Contracts\Postable;
use App\Domain\Posting\PostingBuilder;
use App\Domain\Posting\PostsToLedger;
use App\Domain\Posting\Tags;
use App\Models\Company\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A manual journal: any accounts, must balance. */
class JournalVoucher extends Model implements Postable
{
    use PostsToLedger;
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date', 'total' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalVoucherLine::class)->orderBy('sort');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function buildPostings(PostingBuilder $builder): void
    {
        foreach ($this->lines as $line) {
            $builder->debit($line->account_id, (int) $line->debit, $line->memo, $line->branch_id, Tags::of($line));
            $builder->credit($line->account_id, (int) $line->credit, $line->memo, $line->branch_id, Tags::of($line));
        }
    }

    /** Keeps the cached total in step with the lines. */
    public function refreshTotal(): void
    {
        $this->forceFill(['total' => (int) $this->lines()->sum('debit')])->saveQuietly();
    }
}
