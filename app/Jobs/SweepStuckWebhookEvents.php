<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-dispatch callbacks that were claimed by a worker which then died.
 *
 * The only way an event sits claimed-but-unprocessed past the stale window is
 * that the worker holding it never committed — so the payment was never
 * posted, and re-running is safe. `recordGatewayPayment()` is idempotent on
 * gateway_reference, so even a false positive here cannot double-credit.
 *
 * Without this, a worker killed mid-transaction leaves real money sitting in
 * webhook_events forever: the gateway will not redeliver, because we already
 * answered 200.
 */
class SweepStuckWebhookEvents implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $staleBefore = now()->subMinutes(ProcessXenditCallback::STALE_CLAIM_MINUTES);

        $stuck = WebhookEvent::query()
            ->whereNull('processed_at')
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<', $staleBefore)
            ->orderBy('id')
            ->get();

        foreach ($stuck as $event) {
            Log::warning('Re-dispatching a webhook event whose worker died mid-flight', [
                'webhook_event_id' => $event->id,
                'gateway_event_id' => $event->event_id,
                'attempts' => $event->attempts,
                'claimed_at' => $event->claimed_at?->toIso8601String(),
            ]);

            ProcessXenditCallback::dispatch($event->id);
        }

        // Callbacks that were stored but never picked up at all — the queue was
        // down when they arrived, or the dispatch was lost. Same treatment.
        $neverClaimed = WebhookEvent::query()
            ->whereNull('processed_at')
            ->whereNull('claimed_at')
            ->where('received_at', '<', $staleBefore)
            ->orderBy('id')
            ->get();

        foreach ($neverClaimed as $event) {
            Log::warning('Re-dispatching a webhook event that was never picked up', [
                'webhook_event_id' => $event->id,
                'gateway_event_id' => $event->event_id,
            ]);

            ProcessXenditCallback::dispatch($event->id);
        }
    }
}
