<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Domain\Payments\PaymentLedger;
use App\Jobs\ProcessXenditCallback;
use App\Jobs\SweepStuckWebhookEvents;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\VirtualAccount;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Webhook contract:
 *   verify signature → insert raw payload keyed by gateway event id
 *   → return 200 immediately → dispatch a queue job.
 *
 * Idempotency comes from the UNIQUE constraint, and `paid` is set only here —
 * never by a redirect, never by a controller responding to a user action.
 */
class WebhookHandlingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-callback-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('xendit.callback_token', self::TOKEN);
    }

    /** @param array<string, mixed> $payload */
    private function postCallback(array $payload, ?string $token = self::TOKEN)
    {
        return $this->postJson(
            '/webhooks/xendit',
            $payload,
            $token === null ? [] : ['x-callback-token' => $token],
        );
    }

    /** @return array{0: Company, 1: VirtualAccount} */
    private function customerWithVa(): array
    {
        $company = Company::factory()->create();
        $va = VirtualAccount::factory()->create([
            'company_id' => $company->id,
            'account_number' => '8808123456789',
        ]);

        return [$company, $va];
    }

    // --- the controller ----------------------------------------------------

    public function test_a_callback_with_a_bad_token_is_rejected_and_stored_nowhere(): void
    {
        Queue::fake();

        $this->postCallback(['id' => 'evt_1', 'amount' => 100], token: 'wrong')
            ->assertStatus(401);

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_callback_with_no_token_is_rejected(): void
    {
        $this->postCallback(['id' => 'evt_1', 'amount' => 100], token: null)->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_callbacks_are_refused_when_no_token_is_configured(): void
    {
        // An unconfigured environment must not be able to mark orders paid.
        config()->set('xendit.callback_token', '');

        $this->postCallback(['id' => 'evt_1', 'amount' => 100])->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_a_valid_callback_stores_the_raw_payload_and_returns_200(): void
    {
        Queue::fake();

        $payload = ['id' => 'evt_1', 'payment_id' => 'pay_1', 'amount' => 500_000, 'extra' => 'kept'];

        $this->postCallback($payload)->assertStatus(200);

        $event = WebhookEvent::sole();

        $this->assertSame('pay_1', $event->event_id);
        $this->assertTrue($event->signature_verified);
        $this->assertNull($event->processed_at, 'the controller must not process inline');
        $this->assertSame('kept', $event->payload['extra']);
    }

    public function test_processing_happens_on_the_queue_not_in_the_request(): void
    {
        Queue::fake();

        $this->postCallback(['payment_id' => 'pay_1', 'amount' => 500_000])->assertStatus(200);

        Queue::assertPushed(ProcessXenditCallback::class, 1);
    }

    public function test_a_redelivered_callback_is_stored_once_and_dispatched_once(): void
    {
        Queue::fake();

        $payload = ['payment_id' => 'pay_1', 'amount' => 500_000];

        $this->postCallback($payload)->assertStatus(200);
        $this->postCallback($payload)->assertStatus(200);
        $this->postCallback($payload)->assertStatus(200);

        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessXenditCallback::class, 1);
    }

    public function test_a_callback_without_a_usable_event_id_is_refused(): void
    {
        $this->postCallback(['amount' => 500_000])->assertStatus(422);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    // --- the job -----------------------------------------------------------

    public function test_a_matched_payment_settles_the_order_and_posts_to_the_ledger(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ])->assertStatus(200);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNotNull($order->paid_at);

        $entry = PaymentEntry::sole();
        $this->assertSame(1_110_000, $entry->amount_rupiah);
        $this->assertSame($company->id, $entry->company_id);
        $this->assertSame('xendit', $entry->gateway);

        $this->assertSame(Invoice::STATUS_PAID, $order->invoice->refresh()->status);
    }

    public function test_the_paid_transition_is_logged_with_the_gateway_event(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        $event = $order->events()->where('to_status', OrderStatus::Paid->value)->sole();

        // No human actor — the bank did this, and the callback that caused it
        // is named in the event.
        $this->assertNull($event->actor_id);
        $this->assertSame('pay_1', $event->meta['gateway_event_id']);
    }

    public function test_running_the_job_twice_does_not_double_credit(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        $event = WebhookEvent::sole();

        // Queue jobs must be idempotent — assume they run twice.
        (new ProcessXenditCallback($event->id))->handle(
            app(PaymentLedger::class),
            app(OrderStateMachine::class),
        );

        $this->assertDatabaseCount('payment_entries', 1);
        $this->assertSame(1_110_000, (int) PaymentEntry::sum('amount_rupiah'));
    }

    public function test_a_partial_payment_is_recorded_but_leaves_the_order_unpaid(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 500_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        $this->assertSame(OrderStatus::AwaitingPayment, $order->refresh()->status);
        $this->assertSame(500_000, (int) PaymentEntry::sum('amount_rupiah'));
        $this->assertSame(610_000, $order->invoice->refresh()->amountOutstanding());
    }

    public function test_a_payment_from_an_unknown_va_is_not_posted_to_anyone(): void
    {
        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 500_000,
            'account_number' => 'nobody-has-this',
        ])->assertStatus(200);

        // Stored for a human to look at; never guessed onto a customer.
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('payment_entries', 0);
    }

    public function test_a_payment_with_no_order_reference_lands_in_the_unmatched_queue(): void
    {
        [$company, $va] = $this->customerWithVa();

        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 750_000,
            'account_number' => $va->account_number,
        ])->assertStatus(200);

        $entry = PaymentEntry::sole();

        $this->assertSame($company->id, $entry->company_id);
        $this->assertNull($entry->invoice_id);
        $this->assertSame(1, PaymentEntry::unmatched()->count());
    }

    public function test_a_payment_for_an_order_that_is_not_awaiting_payment_is_still_recorded(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);
        $order->forceFill(['status' => OrderStatus::Draft])->save();

        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ])->assertStatus(200);

        $this->assertSame(OrderStatus::Draft, $order->refresh()->status);
        $this->assertDatabaseCount('payment_entries', 1);
    }

    public function test_the_event_is_marked_processed(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        $this->assertNotNull(WebhookEvent::sole()->processed_at);
    }

    // --- crash safety ------------------------------------------------------

    /**
     * The failure this whole two-marker design exists to prevent.
     *
     * A worker dies partway through — OOM, SIGKILL, a deploy bouncing the
     * queue. Nothing may be left half-written, and the money must still be
     * recoverable, because the gateway already got its 200 and will never
     * redeliver.
     */
    public function test_a_worker_dying_mid_process_commits_nothing(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        Queue::fake();
        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ])->assertStatus(200);

        $event = WebhookEvent::sole();

        // A ledger that posts the payment and then dies, exactly as a killed
        // worker would after writing part of the transaction.
        $exploding = new class(app(AuditLogger::class)) extends PaymentLedger
        {
            public function recordGatewayPayment(
                Company $company,
                int $amountRupiah,
                string $gatewayReference,
                WebhookEvent $webhookEvent,
                ?Invoice $invoice = null,
                ?Order $order = null,
                ?\DateTimeInterface $paidAt = null,
            ): PaymentEntry {
                parent::recordGatewayPayment(
                    $company, $amountRupiah, $gatewayReference,
                    $webhookEvent, $invoice, $order, $paidAt,
                );

                throw new \RuntimeException('worker killed');
            }
        };

        try {
            (new ProcessXenditCallback($event->id))->handle($exploding, app(OrderStateMachine::class));
            $this->fail('Expected the simulated crash to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('worker killed', $e->getMessage());
        }

        $event->refresh();

        // Nothing committed: no payment, no settled order, and — critically —
        // the event is NOT marked processed, so it can still be recovered.
        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->refresh()->status);
        $this->assertNull($event->processed_at);
        $this->assertNotNull($event->process_error);
    }

    public function test_a_crashed_event_reprocesses_cleanly_on_retry(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        Queue::fake();
        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        $event = WebhookEvent::sole();

        // Simulate the aftermath of a hard kill: claimed, never processed.
        $event->forceFill(['claimed_at' => now()->subHour()])->save();

        (new ProcessXenditCallback($event->id))->handle(
            app(PaymentLedger::class),
            app(OrderStateMachine::class),
        );

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1_110_000, (int) PaymentEntry::sum('amount_rupiah'));
        $this->assertNotNull($event->refresh()->processed_at);
    }

    public function test_a_freshly_claimed_event_is_not_stolen_by_a_second_worker(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        Queue::fake();
        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        $event = WebhookEvent::sole();

        // Another worker took it a moment ago and is still working.
        $event->forceFill(['claimed_at' => now()])->save();

        (new ProcessXenditCallback($event->id))->handle(
            app(PaymentLedger::class),
            app(OrderStateMachine::class),
        );

        $this->assertDatabaseCount('payment_entries', 0);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->refresh()->status);
    }

    public function test_the_sweeper_recovers_events_whose_worker_died(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        Queue::fake();
        $this->postCallback([
            'payment_id' => 'pay_1',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        WebhookEvent::sole()->forceFill(['claimed_at' => now()->subHour()])->save();

        (new SweepStuckWebhookEvents)->handle();

        Queue::assertPushed(ProcessXenditCallback::class, 2); // original + sweep
    }

    public function test_the_sweeper_ignores_events_that_are_done_or_still_in_flight(): void
    {
        [$company, $va] = $this->customerWithVa();
        $order = $this->awaitingPaymentOrder($company, 1_110_000);

        // Fully processed.
        $this->postCallback([
            'payment_id' => 'pay_done',
            'amount' => 1_110_000,
            'account_number' => $va->account_number,
            'external_id' => $order->nomor,
        ]);

        // Claimed seconds ago — a worker is still on it. Built directly rather
        // than through the controller, so the only dispatch that can show up
        // below is one the sweeper made.
        WebhookEvent::create([
            'gateway' => 'xendit',
            'event_id' => 'pay_busy',
            'event_type' => 'payment',
            'payload' => ['payment_id' => 'pay_busy', 'amount' => 1_000],
            'signature_verified' => true,
            'received_at' => now()->subHour(),
        ])->forceFill(['claimed_at' => now()])->save();

        Queue::fake();

        (new SweepStuckWebhookEvents)->handle();

        Queue::assertNotPushed(ProcessXenditCallback::class);
    }

    /**
     * An order sitting at awaiting_payment with an invoice already issued.
     */
    private function awaitingPaymentOrder(Company $company, int $total): Order
    {
        $order = Order::factory()
            ->status(OrderStatus::AwaitingPayment)
            ->totalling($total)
            ->create(['company_id' => $company->id]);

        Invoice::factory()->totalling($total)->create([
            'order_id' => $order->id,
            'company_id' => $company->id,
        ]);

        return $order;
    }
}
