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
 * Two different endings, because the two states mean different things:
 *
 *   awaiting_payment  the customer was billed and did not pay  → expired
 *   confirmed         never billed at all                      → rejected
 *
 * Routing a stale `confirmed` order through awaiting_payment first — which an
 * earlier version did, purely to satisfy the state machine — now issues an
 * invoice, so it would bill a customer for an order expiring in the same
 * breath and leave a phantom debt on their account and in their portal.
 * `confirmed → rejected` is a legal transition and the honest one.
 *
 * Idempotent by construction: it only looks at orders still sitting in a
 * reservation-holding state with an expiry in the past, and resolving one
 * moves it out of that set.
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
                if ($order->status === OrderStatus::Confirmed) {
                    $orders->reject(
                        $order,
                        actor: null,
                        alasan: 'Dibatalkan otomatis: belum ditagihkan dan reservasi stok kedaluwarsa.',
                    );

                    continue;
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
