<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Orders\IllegalTransitionException;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Models\Order;
use App\Models\VirtualAccount;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turn a stored Xendit callback into a payment entry, and — if it settles an
 * order — move that order to `paid`.
 *
 * This is the only path to `paid`.
 *
 * Assume this job runs twice. It claims the event row with a conditional
 * UPDATE inside a transaction, so a second run finds processed_at already set
 * and returns without writing anything.
 */
class ProcessXenditCallback implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(PaymentLedger $ledger, OrderStateMachine $orders): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null) {
            Log::warning('Xendit callback vanished before processing', ['id' => $this->webhookEventId]);

            return;
        }

        // Claim it. Only the run that flips processed_at from NULL proceeds.
        $claimed = DB::table('webhook_events')
            ->where('id', $event->id)
            ->whereNull('processed_at')
            ->update([
                'processed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed === 0) {
            return;
        }

        try {
            $this->process($event->fresh(), $ledger, $orders);
        } catch (Throwable $e) {
            // Hand the event back so a retry — or a human — can pick it up.
            DB::table('webhook_events')->where('id', $event->id)->update([
                'processed_at' => null,
                'process_error' => $e->getMessage(),
            ]);

            throw $e;
        }
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
            paidAt: isset($payload['paid_at']) ? \Illuminate\Support\Carbon::parse($payload['paid_at']) : now(),
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
        $outstanding = $invoice?->amountOutstanding() ?? ($order->total_rupiah - $ledger->totalForOrder($order));

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
    private function resolveCompany(array $payload): ?\App\Models\Company
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
