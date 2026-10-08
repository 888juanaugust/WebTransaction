<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalType;
use App\Domain\Numbering\TransactionType;
use App\Domain\Pengaturan\BusinessRule;
use App\Models\Approval\ApprovalRequest;
use App\Models\Company\TransactionApprover;
use App\Models\Sales\SalesOrder;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The sales order's approval, on the shared approval engine. Orders wait
 * only while the Sales Order Approval rule is on: under the governing
 * approval rule when one covers the order, else for anyone with the
 * "approve transactions" right. Each approval passes the credit check first;
 * the order's approval_status follows the engine.
 */
final class OrderApproval
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    /** How the engine treats sales orders; registered by the sales module. */
    public static function type(): ApprovalType
    {
        return new ApprovalType(
            model: SalesOrder::class,
            transactionType: TransactionType::SalesOrder,
            enabled: fn (): bool => BusinessRule::SalesOrderApproval->isOn(),
            requiredWithoutRule: true,
            beforeApprove: fn (SalesOrder $order) => app(CreditCheck::class)->assert($order->customer, (int) $order->total, $order->id),
            changed: fn (SalesOrder $order, ?ApprovalRequest $request, ?User $actor) => self::writeStatus($order, $request, $actor),
            settledBefore: fn (SalesOrder $order): bool => $order->approval_status !== SalesOrder::AWAITING,
        );
    }

    public function initialStatus(): string
    {
        return BusinessRule::SalesOrderApproval->isOn() ? SalesOrder::AWAITING : SalesOrder::APPROVED;
    }

    /** @return Collection<int, TransactionApprover> the active rules that cover this order, the governing one first */
    public function rulesFor(SalesOrder $order): Collection
    {
        return $this->engine->rulesFor($order);
    }

    public function canApprove(SalesOrder $order, ?User $user): bool
    {
        return $this->engine->canApprove($order, $user);
    }

    public function approve(SalesOrder $order, User $approver): void
    {
        $this->engine->approve($order, $approver);
    }

    public function reject(SalesOrder $order, User $approver, string $reason): void
    {
        $this->engine->reject($order, $approver, $reason);
    }

    private static function writeStatus(SalesOrder $order, ?ApprovalRequest $request, ?User $actor): void
    {
        $order->forceFill(match ($request?->status) {
            ApprovalRequest::APPROVED => ['approval_status' => SalesOrder::APPROVED, 'approved_by' => $actor?->id, 'approved_at' => $actor ? now() : null, 'rejection_reason' => null],
            ApprovalRequest::REJECTED => ['approval_status' => SalesOrder::REJECTED, 'approved_by' => $actor?->id, 'approved_at' => now(),
                'rejection_reason' => $request->decisions()->where('decision', ApprovalRequest::REJECTED)->latest('id')->value('reason')],
            default => ['approval_status' => SalesOrder::AWAITING, 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => null],
        })->saveQuietly();
    }
}
