<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Audit\AuditLogger;
use App\Domain\Credit\CreditChecker;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Stock\InsufficientStockException;
use App\Domain\Stock\StockLedger;
use App\Domain\Tax\TaxCalculator;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every order transition lives here.
 *
 * No controller flips `status` in place. Each transition is validated against
 * the state machine, applied inside a transaction, and recorded as an
 * order_events row with actor, timestamps and reason.
 *
 *   draft → submitted → confirmed → awaiting_payment → paid → shipped → completed
 *                ↓           ↓              ↓
 *            rejected    rejected        expired
 *
 * `paid` is deliberately not reachable from staff action — see markPaid().
 */
class OrderStateMachine
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $tax,
        private readonly StockLedger $stock,
        private readonly CreditChecker $credit,
        private readonly AuditLogger $audit,
    ) {}

    public function submit(Order $order, User $actor, ?string $catatan = null): Order
    {
        if ($order->lines()->count() === 0) {
            throw new \DomainException('Order tanpa baris tidak bisa diajukan.');
        }

        return $this->transition($order, OrderStatus::Submitted, $actor, $catatan, function (Order $order) {
            $order->submitted_at = now();
        });
    }

    /**
     * Confirm the order.
     *
     * This is the moment three things happen together, in one transaction:
     *   1. every line takes a price snapshot from resolvePrice()
     *   2. tax is computed per line and stored on the line
     *   3. stock is reserved with a row lock
     *
     * If the credit check or the stock reservation fails, none of it lands.
     *
     * @throws CreditLimitExceededException
     * @throws InsufficientStockException
     */
    public function confirm(Order $order, User $actor, ?string $catatan = null): Order
    {
        $this->assertCan($order, OrderStatus::Confirmed);

        return DB::transaction(function () use ($order, $actor, $catatan) {
            $this->snapshotPrices($order, $actor);

            // Re-read the totals written by the snapshot before checking credit.
            $order->refresh();

            $credit = $this->credit->check($order);

            if (! $credit->passes()) {
                throw new CreditLimitExceededException($credit);
            }

            $this->stock->reserveForOrder($order, $actor);

            return $this->transition(
                $order,
                OrderStatus::Confirmed,
                $actor,
                $catatan,
                function (Order $order) {
                    $order->confirmed_at = now();
                    $order->reservation_expires_at = now()->addMinutes(
                        (int) config('penjualan.reservation_ttl_minutes')
                    );
                },
                meta: ['credit' => $credit->toArray()],
            );
        });
    }

    /** A null actor means the system moved it — the stale-order sweep does. */
    public function awaitPayment(Order $order, ?User $actor = null, ?string $catatan = null): Order
    {
        return $this->transition($order, OrderStatus::AwaitingPayment, $actor, $catatan);
    }

    /**
     * Mark an order paid.
     *
     * Only the gateway webhook job calls this — never a controller responding
     * to a user action, and never a browser redirect. `$actor` is null because
     * the actor is the bank, and the webhook event id is recorded in meta so
     * the transition is traceable back to the callback that caused it.
     *
     * @param  array<string, mixed>  $meta
     */
    public function markPaid(Order $order, array $meta, ?string $catatan = null): Order
    {
        return $this->transition($order, OrderStatus::Paid, null, $catatan, function (Order $order) {
            $order->paid_at = now();
            // The reservation is no longer on a clock once the money is in.
            $order->reservation_expires_at = null;
        }, meta: $meta);
    }

    /**
     * Ship: the reservations become real decrements in the stock ledger.
     */
    public function ship(Order $order, User $actor, ?string $catatan = null): Order
    {
        $this->assertCan($order, OrderStatus::Shipped);

        return DB::transaction(function () use ($order, $actor, $catatan) {
            $movements = $this->stock->shipOrder($order, $actor);

            return $this->transition(
                $order,
                OrderStatus::Shipped,
                $actor,
                $catatan,
                fn (Order $order) => $order->shipped_at = now(),
                meta: ['stock_movement_ids' => array_map(fn ($m) => $m->id, $movements)],
            );
        });
    }

    public function complete(Order $order, User $actor, ?string $catatan = null): Order
    {
        return $this->transition($order, OrderStatus::Completed, $actor, $catatan, function (Order $order) {
            $order->completed_at = now();
        });
    }

    /** Rejecting a confirmed order hands its reserved stock back. */
    public function reject(Order $order, User $actor, string $alasan): Order
    {
        $this->assertCan($order, OrderStatus::Rejected);

        return DB::transaction(function () use ($order, $actor, $alasan) {
            $released = $order->status->holdsReservation()
                ? $this->stock->releaseForOrder($order, 'rejected')
                : 0;

            return $this->transition(
                $order,
                OrderStatus::Rejected,
                $actor,
                $alasan,
                meta: ['reservations_released' => $released],
            );
        });
    }

    /**
     * Expire an unpaid order. Called by the scheduled sweep, so there is no
     * human actor.
     */
    public function expire(Order $order, ?string $alasan = null): Order
    {
        $this->assertCan($order, OrderStatus::Expired);

        return DB::transaction(function () use ($order, $alasan) {
            $released = $this->stock->releaseForOrder($order, 'expired');

            return $this->transition(
                $order,
                OrderStatus::Expired,
                null,
                $alasan ?? 'Kedaluwarsa otomatis: pembayaran tidak diterima.',
                meta: ['reservations_released' => $released],
            );
        });
    }

    /**
     * Copy the resolved price, discount, DPP, PPN and price_list_version_id
     * onto each line.
     *
     * After this runs, rendering the order or its invoice never joins to the
     * live price list again.
     */
    private function snapshotPrices(Order $order, User $actor): void
    {
        $company = $order->company;
        $pricedOn = now();

        $subtotal = 0;
        $discountTotal = 0;
        $dppTotal = 0;
        $ppnTotal = 0;
        $versionId = null;

        foreach ($order->lines()->with('product')->get() as $line) {
            $resolution = $this->prices->resolve($company, $line->sku, $line->qty_base, $pricedOn);

            $unitPrice = $resolution->requireUnitPrice();
            $lineTotal = $unitPrice * $line->qty_base;

            $listTotal = ($resolution->listPrice ?? $unitPrice) * $line->qty_base;
            $lineDiscount = max(0, $listTotal - $lineTotal);

            $breakdown = $this->tax->forLine($lineTotal);

            $product = $line->product;

            $line->forceFill([
                'unit_price_rupiah' => $unitPrice,
                'discount_rupiah' => $lineDiscount,
                'line_total_rupiah' => $lineTotal,
                'dpp_rupiah' => $breakdown->dpp,
                'ppn_rupiah' => $breakdown->ppn,
                'price_list_version_id' => $resolution->priceListVersionId,
                'price_reason' => $resolution->reason->value,
                'price_reason_meta' => $resolution->toArray(),
                'priced_at' => $pricedOn,
                'merk_snapshot' => $product?->merk,
                'description_snapshot' => $product?->description,
                'qty_per_ctn_snapshot' => $product?->qty_per_ctn ?? $line->qty_per_ctn_snapshot,
                'satuan_dasar_snapshot' => $product?->satuan_dasar ?? $line->satuan_dasar_snapshot,
            ])->save();

            $subtotal += $lineTotal;
            $discountTotal += $lineDiscount;
            $dppTotal += $breakdown->dpp;
            $ppnTotal += $breakdown->ppn;
            $versionId ??= $resolution->priceListVersionId;
        }

        $order->forceFill([
            'subtotal_rupiah' => $subtotal,
            'discount_rupiah' => $discountTotal,
            'dpp_rupiah' => $dppTotal,
            'ppn_rupiah' => $ppnTotal,
            // The customer pays the selling price plus PPN. DPP is a reporting
            // figure, not a billing one.
            'total_rupiah' => $subtotal + $ppnTotal,
            'price_list_version_id' => $versionId,
        ])->save();

        $this->audit->log(
            action: 'order_priced',
            subject: $order,
            newValue: [
                'subtotal_rupiah' => $subtotal,
                'ppn_rupiah' => $ppnTotal,
                'total_rupiah' => $subtotal + $ppnTotal,
                'price_list_version_id' => $versionId,
            ],
            actor: $actor,
        );
    }

    /**
     * The single place `status` is written.
     *
     * @param  (callable(Order): void)|null  $mutate
     * @param  array<string, mixed>  $meta
     */
    private function transition(
        Order $order,
        OrderStatus $to,
        ?User $actor,
        ?string $alasan = null,
        ?callable $mutate = null,
        array $meta = [],
    ): Order {
        $from = $order->status;

        $this->assertCan($order, $to);

        return DB::transaction(function () use ($order, $from, $to, $actor, $alasan, $mutate, $meta) {
            $order->status = $to;

            if ($mutate !== null) {
                $mutate($order);
            }

            $order->save();

            OrderEvent::create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor?->id,
                'alasan' => $alasan,
                'meta' => $meta === [] ? null : $meta,
            ]);

            $this->audit->log(
                action: 'order_transition',
                subject: $order,
                oldValue: ['status' => $from->value],
                newValue: ['status' => $to->value],
                actor: $actor,
                alasan: $alasan,
            );

            return $order;
        });
    }

    private function assertCan(Order $order, OrderStatus $to): void
    {
        if (! $order->status->canTransitionTo($to)) {
            throw new IllegalTransitionException($order->status, $to);
        }
    }
}
