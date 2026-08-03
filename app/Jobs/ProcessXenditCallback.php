<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Orders\IllegalTransitionException;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Models\Company;
use App\Models\Order;
use App\Models\VirtualAccount;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turn a stored Xendit callback into a payment entry, and — if it settles an
 * order — move that order to `paid`.
 *
 * This is the only path to `paid`.
 *
 * Crash safety is the whole design here. There are two markers, deliberately:
 *
 *   claimed_at   a worker has picked this up
 *   processed_at the work is done AND committed
 *
 * The claim is committed on its own so two workers cannot both run. The work
 * then happens inside a single transaction that writes the payment entry, the
 * order transition, and processed_at together. If the worker dies at any point
 * in that transaction — exception, OOM, SIGKILL, a deploy bouncing the queue —
 * Postgres rolls the whole thing back, processed_at stays NULL, and
 * SweepStuckWebhookEvents re-dispatches it once the claim goes stale.
 *
 * Marking processed_at up front (the obvious approach) loses money: the event
 * looks done, no payment was ever posted, and the UNIQUE constraint on
 * event_id means the gateway's redelivery cannot rescue it either.
 *
 * Second line of defence: PaymentLedger::recordGatewayPayment() is idempotent
 * on gateway_reference, so even a re-run that races cannot double-credit.
 */
class ProcessXenditCallback implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    /** How long a claim may sit before the sweeper assumes the worker died. */
    public const STALE_CLAIM_MINUTES = 15;

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(PaymentLedger $ledger, OrderStateMachine $orders): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null) {
            Log::warning('Xendit callback vanished before processing', ['id' => $this->webhookEventId]);

            return;
        }

        if (! $this->claim($event)) {
            return;
        }

        try {
            // One transaction: the money, the order, and the done-marker.
            DB::transaction(function () use ($event, $ledger, $orders) {
                $this->process($event->fresh(), $ledger, $orders);

                DB::table('webhook_events')
                    ->where('id', $event->id)
                    ->update(['processed_at' => now(), 'process_error' => null]);
            });
        } catch (Throwable $e) {
            // Release the claim so a retry can pick it up immediately rather
            // than waiting out the stale window. processed_at was rolled back
            // with everything else, so there is nothing to undo.
            DB::table('webhook_events')->where('id', $event->id)->update([
                'claimed_at' => null,
                'process_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Take the event, if it is free.
     *
     * A conditional UPDATE is the lock: exactly one worker gets a row count of
     * 1. Already-processed events are skipped outright; an event whose claim
     * has gone stale is fair game again, because the only way that happens is
     * the previous worker dying before it committed anything.
     */
    private function claim(WebhookEvent $event): bool
    {
        $staleBefore = now()->subMinutes(self::STALE_CLAIM_MINUTES);

        $claimed = DB::table('webhook_events')
            ->where('id', $event->id)
            ->whereNull('processed_at')
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', $staleBefore))
            ->update([
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        return $claimed === 1;
    }

    private function process(WebhookEvent $event, PaymentLedger $ledger, OrderStateMachine $orders): void
    {
        $payload = $event->payload ?? [];

        $amount = (int) ($payload['amount'] ?? 0);

        if ($amount <= 0) {
            Log::info('Xendit callback carried no amount; nothing to post', ['event_id' => $event->event_id]);

            return;
        }

        $company = $this->resolveCompany($payload);

        if ($company === null) {
            // Money we can't attribute still gets recorded — it lands in the
            // "payments received but unmatched" admin queue rather than being
            // dropped on the floor.
            Log::warning('Xendit payment could not be matched to a company', [
                'event_id' => $event->event_id,
            ]);

            return;
        }

        $order = $this->resolveOrder($payload, $company->id);

        $entry = $ledger->recordGatewayPayment(
            company: $company,
            amountRupiah: $amount,
            gatewayReference: (string) ($payload['payment_id'] ?? $payload['id'] ?? $event->event_id),
            webhookEvent: $event,
            invoice: $order?->invoice,
            order: $order,
            paidAt: isset($payload['paid_at']) ? Carbon::parse($payload['paid_at']) : now(),
        );

        if ($order === null) {
            return;
        }

        if ($order->status !== OrderStatus::AwaitingPayment) {
            Log::info('Xendit payment posted against an order not awaiting payment', [
                'order_id' => $order->id,
                'status' => $order->status->value,
            ]);

            return;
        }

        // Only settle the order when the money actually covers it. A partial
        // transfer stays on the ledger and shows up in the AR worklist.
        $invoice = $order->invoice;
        $outstanding = $invoice?->fresh()?->amountOutstanding()
            ?? ($order->total_rupiah - $ledger->totalForOrder($order));

        if ($outstanding > 0) {
            Log::info('Partial payment received; order stays awaiting_payment', [
                'order_id' => $order->id,
                'outstanding' => $outstanding,
            ]);

            return;
        }

        try {
            $orders->markPaid($order, meta: [
                'webhook_event_id' => $event->id,
                'gateway_event_id' => $event->event_id,
                'payment_entry_id' => $entry->id,
                'amount_rupiah' => $amount,
            ]);
        } catch (IllegalTransitionException $e) {
            Log::warning('Could not mark order paid', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fixed VA callbacks identify the payer by the VA account number, which we
     * issued and stored against the company.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveCompany(array $payload): ?Company
    {
        $accountNumber = $payload['account_number'] ?? $payload['callback_virtual_account_id'] ?? null;

        if (is_string($accountNumber) && $accountNumber !== '') {
            $va = VirtualAccount::query()
                ->where('account_number', $accountNumber)
                ->orWhere('external_id', $accountNumber)
                ->orWhere('gateway_id', $accountNumber)
                ->first();

            if ($va !== null) {
                return $va->company;
            }
        }

        return null;
    }

    /**
     * With a fixed VA the transfer itself carries no order number, so the
     * buyer's reference is the only hint. When it's absent or unusable the
     * payment is left unmatched for finance to allocate.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrder(array $payload, int $companyId): ?Order
    {
        $reference = $payload['external_id'] ?? $payload['description'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return Order::query()
            ->where('company_id', $companyId)
            ->where('nomor', trim($reference))
            ->first();
    }
}
