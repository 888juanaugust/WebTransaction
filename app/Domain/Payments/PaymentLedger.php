<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Orders\OrderStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Money is an append-only ledger, same as stock.
 *
 * A payment row is never mutated. Corrections are reversing entries: same
 * amount, opposite sign, pointing back at what they undo.
 */
class PaymentLedger
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentPoster $poster,
    ) {}

    /**
     * Post a payment that arrived through the gateway.
     *
     * Idempotent on gateway_reference: if a retried job gets this far twice,
     * the second call returns the existing entry rather than double-crediting.
     */
    public function recordGatewayPayment(
        Company $company,
        int $amountRupiah,
        string $gatewayReference,
        WebhookEvent $webhookEvent,
        ?Invoice $invoice = null,
        ?Order $order = null,
        ?DateTimeInterface $paidAt = null,
    ): PaymentEntry {
        if ($amountRupiah <= 0) {
            throw new LogicException('A gateway payment must be positive.');
        }

        return DB::transaction(function () use (
            $company, $amountRupiah, $gatewayReference, $webhookEvent, $invoice, $order, $paidAt
        ) {
            $existing = PaymentEntry::query()
                ->where('gateway', 'xendit')
                ->where('gateway_reference', $gatewayReference)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $entry = PaymentEntry::create([
                'company_id' => $company->id,
                'invoice_id' => $invoice?->id,
                'order_id' => $order?->id,
                'amount_rupiah' => $amountRupiah,
                'kind' => PaymentEntry::KIND_PAYMENT,
                'gateway' => 'xendit',
                'gateway_reference' => $gatewayReference,
                'webhook_event_id' => $webhookEvent->id,
                'paid_at' => $paidAt ?? now(),
            ]);

            $this->settleInvoiceIfCovered($invoice, ['gateway_event_id' => $gatewayReference]);

            // Dr Bank / Cr Piutang Usaha.
            $this->poster->customerPaymentReceived($entry);

            $this->audit->log(
                action: 'payment_received',
                subject: $entry,
                newValue: [
                    'amount_rupiah' => $amountRupiah,
                    'gateway_reference' => $gatewayReference,
                    'invoice_id' => $invoice?->id,
                ],
                actor: null,
            );

            return $entry;
        });
    }

    /**
     * Post a payment finance keyed in by hand — a bank transfer that did not
     * come through the gateway, or a cash payment at the counter.
     */
    public function recordManualPayment(
        Company $company,
        int $amountRupiah,
        User $actor,
        ?Invoice $invoice = null,
        ?string $catatan = null,
        ?DateTimeInterface $paidAt = null,
    ): PaymentEntry {
        if (! $actor->role()->canConfirmPayment()) {
            throw new LogicException("Role {$actor->role()->value} tidak boleh mengonfirmasi pembayaran.");
        }

        return DB::transaction(function () use ($company, $amountRupiah, $actor, $invoice, $catatan, $paidAt) {
            $entry = PaymentEntry::create([
                'company_id' => $company->id,
                'invoice_id' => $invoice?->id,
                'amount_rupiah' => $amountRupiah,
                'kind' => PaymentEntry::KIND_PAYMENT,
                'actor_id' => $actor->id,
                'paid_at' => $paidAt ?? now(),
                'catatan' => $catatan,
            ]);

            $this->settleInvoiceIfCovered($invoice);

            $this->poster->customerPaymentReceived($entry, $actor);

            $this->audit->log(
                action: 'payment_confirmed',
                subject: $entry,
                newValue: ['amount_rupiah' => $amountRupiah, 'invoice_id' => $invoice?->id],
                actor: $actor,
                alasan: $catatan,
            );

            return $entry;
        });
    }

    /**
     * Undo an entry by inserting its opposite. The original row is left
     * exactly as it was.
     */
    public function reverse(PaymentEntry $entry, User $actor, string $alasan): PaymentEntry
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new LogicException("Role {$actor->role()->value} tidak boleh membalik pembayaran.");
        }

        return DB::transaction(function () use ($entry, $actor, $alasan) {
            $reversal = PaymentEntry::create([
                'company_id' => $entry->company_id,
                'invoice_id' => $entry->invoice_id,
                'order_id' => $entry->order_id,
                'amount_rupiah' => -$entry->amount_rupiah,
                'kind' => PaymentEntry::KIND_REVERSAL,
                'gateway' => $entry->gateway,
                'actor_id' => $actor->id,
                'reverses_entry_id' => $entry->id,
                'paid_at' => now(),
                'catatan' => $alasan,
            ]);

            // Reopen the invoice if it no longer covers itself.
            $invoice = $entry->invoice;

            if ($invoice !== null && $invoice->status === Invoice::STATUS_PAID
                && $invoice->fresh()->amountOutstanding() > 0) {
                $invoice->forceFill(['status' => Invoice::STATUS_OPEN])->save();
            }

            /*
             * The reversal is its own row with a negative amount, so it posts
             * its own entry with both sides the other way round. The original
             * entry is not touched — same rule as the payment ledger itself.
             */
            $this->poster->customerPaymentReceived($reversal, $actor);

            $this->audit->log(
                action: 'payment_reversed',
                subject: $reversal,
                oldValue: ['payment_entry_id' => $entry->id, 'amount_rupiah' => $entry->amount_rupiah],
                newValue: ['amount_rupiah' => -$entry->amount_rupiah],
                actor: $actor,
                alasan: $alasan,
            );

            return $reversal;
        });
    }

    /** Match an unallocated payment to an invoice. */
    public function allocateToInvoice(PaymentEntry $entry, Invoice $invoice, User $actor): PaymentEntry
    {
        if (! $actor->role()->canConfirmPayment()) {
            throw new LogicException("Role {$actor->role()->value} tidak boleh mencocokkan pembayaran.");
        }

        if ($entry->invoice_id !== null) {
            throw new LogicException('Pembayaran ini sudah dicocokkan ke faktur lain.');
        }

        return DB::transaction(function () use ($entry, $invoice, $actor) {
            // Allocation is bookkeeping metadata, not an amount change — the
            // money itself is untouched, so this is not a ledger mutation.
            $entry->forceFill([
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->order_id,
            ])->save();

            $this->settleInvoiceIfCovered($invoice);

            $this->audit->log(
                action: 'payment_allocated',
                subject: $entry,
                oldValue: ['invoice_id' => null],
                newValue: ['invoice_id' => $invoice->id],
                actor: $actor,
            );

            return $entry;
        });
    }

    public function totalForOrder(Order $order): int
    {
        return (int) PaymentEntry::query()->where('order_id', $order->id)->sum('amount_rupiah');
    }

    /** @param  array<string, mixed>  $meta  carried onto the order's transition event */
    private function settleInvoiceIfCovered(?Invoice $invoice, array $meta = []): void
    {
        if ($invoice === null) {
            return;
        }

        $invoice = $invoice->fresh();

        if ($invoice->status === Invoice::STATUS_OPEN && $invoice->amountOutstanding() <= 0) {
            $invoice->forceFill(['status' => Invoice::STATUS_PAID])->save();

            $this->advanceOrder($invoice, $meta);
        }
    }

    /**
     * Settlement moves the order, because "finished" means paid.
     *
     * Two credit-sales positions land here. An order still awaiting payment
     * becomes `paid` — the prepay path. An order already shipped becomes
     * `completed` — the ordinary credit path, where the goods left months
     * before the money arrived and the last rupiah is what closes the file.
     * The transitions are logged like any other, with no actor: the money is
     * the actor.
     *
     * Resolved lazily rather than constructor-injected: the state machine
     * posts through this ledger's own collaborators, and a constructor cycle
     * is a worse smell than one container call in a private method.
     */
    /** @param  array<string, mixed>  $meta */
    private function advanceOrder(Invoice $invoice, array $meta = []): void
    {
        $order = $invoice->order;

        if ($order === null) {
            return;
        }

        $machine = app(OrderStateMachine::class);

        if ($order->status === OrderStatus::AwaitingPayment) {
            $machine->markPaid($order, meta: $meta + ['invoice_id' => $invoice->id, 'settled' => true]);

            return;
        }

        if ($order->status === OrderStatus::Shipped) {
            $machine->complete($order, null, 'Faktur lunas — transaksi selesai.');
        }
    }
}
