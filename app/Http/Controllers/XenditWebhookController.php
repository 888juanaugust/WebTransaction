<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessXenditCallback;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Xendit fixed-VA callbacks.
 *
 * The contract, in order:
 *   1. verify the callback token
 *   2. insert the raw payload into webhook_events, keyed by the gateway's
 *      event id — the UNIQUE constraint is what makes this idempotent
 *   3. return 200 immediately
 *   4. dispatch a queue job to do the actual work
 *
 * Nothing is processed inline. A callback that arrives twice inserts once and
 * dispatches once; a callback that arrives while the queue is backed up still
 * gets its 200 and is not retried by Xendit.
 */
class XenditWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->tokenIsValid($request)) {
            Log::warning('Xendit callback rejected: bad callback token', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid callback token.'], 401);
        }

        $payload = $request->all();
        $eventId = $this->eventId($request, $payload);

        if ($eventId === null) {
            Log::warning('Xendit callback rejected: no usable event id', ['payload' => $payload]);

            return response()->json(['message' => 'Missing event id.'], 422);
        }

        // insertOrIgnore, not create(): a redelivery must not raise, it must
        // quietly do nothing and still get its 200.
        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'gateway' => 'xendit',
            'event_id' => $eventId,
            'event_type' => $payload['event'] ?? $request->header('x-callback-event') ?? 'payment',
            'payload' => json_encode($payload),
            'signature_verified' => true,
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            // Already have it. Do not dispatch again.
            return response()->json(['message' => 'Duplicate, ignored.'], 200);
        }

        $event = WebhookEvent::query()
            ->where('gateway', 'xendit')
            ->where('event_id', $eventId)
            ->firstOrFail();

        ProcessXenditCallback::dispatch($event->id);

        return response()->json(['message' => 'Received.'], 200);
    }

    /**
     * Xendit authenticates callbacks with a static token in the
     * x-callback-token header, compared in constant time.
     */
    private function tokenIsValid(Request $request): bool
    {
        $expected = (string) config('xendit.callback_token');

        if ($expected === '') {
            // Refusing beats accepting anything when the token is unset — an
            // unconfigured environment must not be able to mark orders paid.
            Log::error('XENDIT_CALLBACK_TOKEN is not configured; rejecting callback.');

            return false;
        }

        return hash_equals($expected, (string) $request->header('x-callback-token'));
    }

    /**
     * The gateway's own id for this event.
     *
     * Fixed VA payment callbacks carry `payment_id`; other callback shapes use
     * `id`. Falling back to the delivery header keeps a payload we don't
     * recognise from colliding with an unrelated one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventId(Request $request, array $payload): ?string
    {
        foreach (['payment_id', 'id', 'callback_id'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return $payload[$key];
            }
        }

        $header = $request->header('webhook-id');

        return is_string($header) && $header !== '' ? $header : null;
    }
}
