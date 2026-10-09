<?php

declare(strict_types=1);

namespace App\Client\Models;

use App\Client\Domain\Claims\ClaimStatus;
use App\Domain\Audit\HasAuditReference;
use App\Domain\Audit\RecordsActivity;
use App\Models\Company\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What every two-key claim carries: who filed it, who decided it and how,
 * the branch it belongs to. A claim has no number; it is known by its id and
 * by the document its verification made.
 */
abstract class Claim extends Model implements HasAuditReference
{
    use RecordsActivity;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'decided_at' => 'datetime'];
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isFiled(): bool
    {
        return $this->status === ClaimStatus::FILED;
    }

    /** The claims this user may see: every one for an administrator, else the user's branches and, for a seat, their own customers. */
    abstract public function scopeVisibleTo(Builder $query, ?User $user): Builder;

    public function auditReference(): string
    {
        return '#'.$this->getKey();
    }
}
