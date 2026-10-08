<?php

namespace App\Models\Purchasing;

use App\Domain\Approval\RequiresApproval;
use App\Domain\Audit\HasAuditReference;
use App\Models\Company\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Vendor Claim: goods sent to or received from a vendor under a claim, without a sale or purchase. Not posted. */
class VendorClaim extends Model implements HasAuditReference
{
    use RequiresApproval;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['trans_date' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorClaimLine::class)->orderBy('sort');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function auditReference(): string
    {
        return $this->number;
    }
}
