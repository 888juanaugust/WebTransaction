<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Orders\IllegalTransitionException;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Release stock held by orders that were confirmed but never paid.
 *
 * Idempotent by construction: it only looks at orders still sitting in a
 * reservation-holding state with an expiry in the past, and expiring one moves
 * it out of that set. Running twice releases nothing the second time.
 */
class ReleaseStaleReservations implements ShouldQueue
{
    use Queueable;

    public function handle(OrderStateMachine $orders): void
    {
        $stale = Order::query()
            ->whereIn('status', [OrderStatus::Confirmed, OrderStatus::AwaitingPayment])
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($stale as $order) {
            try {
                // A confirmed order has to pass through awaiting_payment
                // before it can expire — the state machine says so, and the
                // event log should show both steps.
                if ($order->status === OrderStatus::Confirmed) {
                    $orders->awaitPayment($order, catatan: 'Otomatis sebelum kedaluwarsa.');
                }

                $orders->expire($order);
            } catch (IllegalTransitionException $e) {
                // Someone paid or rejected it between the query and here.
                Log::info('Skipped stale reservation release', [
                    'order_id' => $order->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
