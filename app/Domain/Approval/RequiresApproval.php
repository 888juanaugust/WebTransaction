<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\Approval\ApprovalRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** For a document type registered with the approval engine: its requests, and a scope for the approved ones. */
trait RequiresApproval
{
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable')->orderBy('id');
    }

    /** The request of the document's current version. */
    public function approvalRequest(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable')->whereNull('superseded_at');
    }

    /** Documents that may be pulled, printed or settled: no current request waiting or rejected. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereDoesntHave('approvalRequest', fn (Builder $r) => $r->where('status', '!=', ApprovalRequest::APPROVED));
    }
}
