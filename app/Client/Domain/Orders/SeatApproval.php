<?php

declare(strict_types=1);

namespace App\Client\Domain\Orders;

use App\Client\Domain\Stock\Reservations;
use App\Client\Domain\Teams\TeamAssigner;
use App\Domain\Approval\ApprovalType;
use App\Domain\Sales\OrderApproval;
use App\Models\Approval\ApprovalRequest;
use App\Models\Sales\SalesOrder;
use App\Models\User;
use RuntimeException;

/**
 * Central's approval of a sales order, on the base's engine: the customer's
 * marketing seat or an administrator decides (a customer with no seat yet
 * follows the base's approval rules), the base's credit check runs, and the
 * reservations ledger follows the decision.
 */
final class SeatApproval
{
    public static function type(): ApprovalType
    {
        $base = OrderApproval::type();

        return new ApprovalType(
            model: SalesOrder::class,
            transactionType: $base->transactionType,
            enabled: $base->enabled,
            requiredWithoutRule: $base->requiredWithoutRule,
            beforeApprove: function (SalesOrder $order, User $approver) use ($base): void {
                self::assertSeat($order, $approver);
                ($base->beforeApprove)($order, $approver);
                app(OrderSplitter::class)->assertNotScattered($order);
            },
            changed: function (SalesOrder $order, ?ApprovalRequest $request, ?User $actor) use ($base): void {
                ($base->changed)($order, $request, $actor);
                app(Reservations::class)->sync($order->fresh(), $request);
            },
            settledBefore: $base->settledBefore,
        );
    }

    public static function assertSeat(SalesOrder $order, User $approver): void
    {
        $customer = $order->customer;
        if ($customer?->marketing_user_id !== null && ! TeamAssigner::holdsApprovalSeat($approver, $customer)) {
            throw new RuntimeException(__('Only :name\'s marketing seat or an administrator approves this order.', ['name' => $customer->name]));
        }
    }
}
