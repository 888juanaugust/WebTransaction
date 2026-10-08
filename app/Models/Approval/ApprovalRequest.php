<?php

namespace App\Models\Approval;

use App\Models\Company\TransactionApprover;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One version of a document waiting for, or past, its approval: the rule it
 * fell under (frozen once someone decides), the approver slots in order, and
 * how many approvals complete it. An edit that changes the amount, the branch
 * or the lines supersedes it with a new request.
 */
class ApprovalRequest extends Model
{
    public const AWAITING = 'awaiting';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** No rule covers the document and its type needs none: approved on save. */
    public const RULE_NONE = 'none';

    /** No rule covers the document but its type needs approval: anyone with the approve right. */
    public const RULE_RIGHT = 'right';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['slots' => 'array', 'amount' => 'integer', 'required_count' => 'integer', 'decided_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TransactionApprover::class, 'transaction_approver_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function isAwaiting(): bool
    {
        return $this->status === self::AWAITING;
    }
}
