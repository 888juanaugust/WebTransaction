<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Domain\Numbering\TransactionType;
use App\Models\Approval\ApprovalRequest;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A document type the approval engine knows: which model, which transaction
 * type the approval rules name it by, and how the type behaves.
 *
 * - enabled: whether approval applies at all (sales orders: only while the
 *   Sales Order Approval rule is on); null means always.
 * - requiredWithoutRule: when no rule covers a document, it still waits for
 *   anyone with the "approve transactions" right (sales orders under the
 *   rule, stock counts); otherwise it is approved on save.
 * - beforeApprove(document, approver): may refuse by throwing (the credit check).
 * - changed(document, request, ?actor): told whenever the request opens or is decided.
 * - settledBefore(document): for a document saved before approvals were
 *   recorded, whether it already counts as approved (default: yes).
 */
final class ApprovalType
{
    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(): bool)|null  $enabled
     * @param  (Closure(Model, User): void)|null  $beforeApprove
     * @param  (Closure(Model, ?ApprovalRequest, ?User): void)|null  $changed
     * @param  (Closure(Model): bool)|null  $settledBefore
     */
    public function __construct(
        public readonly string $model,
        public readonly TransactionType $transactionType,
        public readonly ?Closure $enabled = null,
        public readonly bool $requiredWithoutRule = false,
        public readonly ?Closure $beforeApprove = null,
        public readonly ?Closure $changed = null,
        public readonly ?Closure $settledBefore = null,
    ) {}

    /** A document with no request yet (saved before approvals were recorded): approved unless the type says otherwise. */
    public function approvedWithoutRequest(Model $document): bool
    {
        return $this->settledBefore === null || (bool) ($this->settledBefore)($document);
    }

    public function isEnabled(): bool
    {
        return $this->enabled === null || (bool) ($this->enabled)();
    }
}
