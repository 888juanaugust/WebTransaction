<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Access\Role;
use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Credit\CreditChecker;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Regions\RegionContext;
use App\Domain\Stock\InsufficientStockException;
use App\Domain\Stock\StockLedger;
use App\Domain\Tax\TaxCalculator;
use App\Models\CustomerUser;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
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
        private readonly InvoiceIssuer $invoices,
        private readonly DocumentPoster $poster,
    ) {}

    public function submit(Order $order, User $actor, ?string $catatan = null): Order
    {
        return $this->doSubmit($order, $actor, null, $catatan);
    }

    /**
     * A buyer submitting their own order from the portal.
     *
     * Deliberately stops at `submitted`, exactly like a sales-entered order:
     * the buyer proposes, staff confirm. Confirmation is where credit is
     * checked and stock is reserved, and letting a customer do that for
     * themselves would be letting them approve their own credit.
     */
    public function submitAsBuyer(Order $order, CustomerUser $buyer, ?string $catatan = null): Order
    {
        return $this->doSubmit($order, null, $buyer, $catatan);
    }

    private function doSubmit(
        Order $order,
        ?User $actor,
        ?CustomerUser $buyer,
        ?string $catatan,
    ): Order {
        if ($order->lines()->count() === 0) {
            throw new \DomainException('Order tanpa baris tidak bisa diajukan.');
        }

        return $this->transition(
            $order,
            OrderStatus::Submitted,
            $actor,
            $catatan,
            function (Order $order) {
                $order->submitted_at = now();
            },
            customerActor: $buyer,
        );
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
        $this->assertMayApprove($order, $actor);

        /*
         * Where will this actually ship from? The planner drains the home
         * region first and reaches into other regions only for what home
         * cannot cover. One warehouse is the common case and takes the
         * ordinary path; several breaks the order into one transaction per
         * warehouse, each in that warehouse's region and books — the rule
         * the 2026-08 multi-warehouse spec set.
         */
        $plan = app(OrderSplitter::class)->plan($order);

        if (count($plan) === 1 && array_key_exists((int) $order->warehouse_id, $plan)) {
            return $this->confirmSingle($order, $actor, $catatan);
        }

        return $this->confirmSplit($order, $actor, $catatan, $plan);
    }

    /** The ordinary approval: one warehouse, one transaction. */
    private function confirmSingle(Order $order, User $actor, ?string $catatan): Order
    {
        return $this->inRegion($order, fn () => DB::transaction(function () use ($order, $actor, $catatan) {
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
        }));
    }

    /**
     * The goods are scattered: break the order into one transaction per
     * warehouse and confirm each in its own region's books.
     *
     * One database transaction around the whole of it — the sibling
     * creation, the line moves, and every confirm. A customer either gets
     * all X transactions approved or stays submitted; a split half-approved
     * would reserve stock for goods the credit check then refuses.
     *
     * The original order keeps its number and carries the share of the
     * first-priority warehouse. When the home warehouse ships nothing at
     * all, the original is re-homed to the first shipping warehouse —
     * region, books and a fresh number from that region's counter —
     * because a transaction books where its goods are, and an order with
     * no goods is not a transaction.
     *
     * @param  array<int, list<array{line_id: int, sku: string, qty_base: int}>>  $plan
     */
    private function confirmSplit(Order $order, User $actor, ?string $catatan, array $plan): Order
    {
        return DB::transaction(function () use ($order, $actor, $catatan, $plan) {
            $warehouses = Warehouse::query()
                ->withoutGlobalScope('region')
                ->findMany(array_keys($plan))
                ->keyBy('id');

            // The parent's share: its own warehouse when it ships anything,
            // otherwise the plan's first warehouse (re-homing the order).
            $parentWarehouseId = array_key_exists((int) $order->warehouse_id, $plan)
                ? (int) $order->warehouse_id
                : (int) array_key_first($plan);

            $lines = $order->lines()->orderBy('urutan')->orderBy('id')->get()->keyBy('id');
            $orders = [];

            foreach ($plan as $warehouseId => $shares) {
                $warehouse = $warehouses[$warehouseId];

                if ((int) $warehouseId === $parentWarehouseId) {
                    $this->rehomeParent($order, $warehouse);
                    $this->resizeLines($order, $lines, $shares);
                    $orders[] = $order->refresh();

                    continue;
                }

                $orders[] = $this->spawnSibling($order, $warehouse, $lines, $shares);
            }

            $nomors = array_map(fn (Order $o) => $o->nomor, $orders);

            $this->audit->log(
                action: 'order_split',
                subject: $order,
                newValue: ['pecahan' => $nomors, 'gudang' => count($plan)],
                actor: $actor,
                alasan: $catatan,
            );

            $confirmed = [];

            foreach ($orders as $piece) {
                $confirmed[] = $this->confirmSingle($piece->refresh(), $actor, $catatan);
            }

            return $confirmed[0];
        });
    }

    /**
     * Point the parent at its shipping warehouse. A no-op when it already
     * ships from home; a full re-home — region, books, fresh number from
     * the new region's counter — when home ships nothing.
     */
    private function rehomeParent(Order $order, Warehouse $warehouse): void
    {
        if ((int) $order->warehouse_id === (int) $warehouse->id) {
            return;
        }

        $nomorLama = $order->nomor;

        app(RegionContext::class)->within((int) $warehouse->region_id, function () use ($order, $warehouse) {
            $order->forceFill([
                'warehouse_id' => $warehouse->id,
                'region_id' => $warehouse->region_id,
                'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
            ])->save();
        });

        $this->audit->log(
            action: 'order_transition',
            subject: $order,
            oldValue: ['nomor' => $nomorLama],
            newValue: ['nomor' => $order->nomor, 'gudang' => $warehouse->kode],
            alasan: 'Gudang asal tidak memegang stok — order pindah buku ke wilayah gudang pengirim.',
        );
    }

    /**
     * Shrink the parent's lines to its own share; a line the parent ships
     * none of moves wholly to the siblings and leaves the parent.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @param  list<array{line_id: int, sku: string, qty_base: int}>  $shares
     */
    private function resizeLines(Order $order, $lines, array $shares): void
    {
        $shareByLine = collect($shares)->keyBy('line_id');

        foreach ($lines as $line) {
            $share = $shareByLine->get($line->id);

            if ($share === null) {
                $line->delete();

                continue;
            }

            if ((int) $share['qty_base'] !== (int) $line->qty_base) {
                /*
                 * A split mid-line ships in base units by definition — six
                 * of a ten-piece carton is not "0.6 CTN" on any document a
                 * warehouse can pick.
                 */
                $line->forceFill([
                    'qty_base' => $share['qty_base'],
                    'ordered_unit' => $line->satuan_dasar_snapshot,
                    'ordered_qty' => $share['qty_base'],
                ])->save();
            }
        }
    }

    /**
     * One sibling order in the shipping warehouse's region, carrying that
     * warehouse's shares, already submitted — it was submitted as part of
     * the parent, and its trail says so.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @param  list<array{line_id: int, sku: string, qty_base: int}>  $shares
     */
    private function spawnSibling(Order $parent, Warehouse $warehouse, $lines, array $shares): Order
    {
        return app(RegionContext::class)->within((int) $warehouse->region_id, function () use ($parent, $warehouse, $lines, $shares) {
            $sibling = new Order;
            $sibling->forceFill([
                'nomor' => app(DocumentNumberGenerator::class)->nextOrderNumber(),
                'company_id' => $parent->company_id,
                'warehouse_id' => $warehouse->id,
                'created_by' => $parent->created_by,
                'sales_user_id' => $parent->sales_user_id,
                'placed_by_customer_user_id' => $parent->placed_by_customer_user_id,
                'po_pelanggan' => $parent->po_pelanggan,
                'catatan' => $parent->catatan,
                'split_parent_id' => $parent->id,
                'status' => OrderStatus::Submitted,
                'submitted_at' => $parent->submitted_at ?? now(),
            ])->save();

            foreach ($shares as $urutan => $share) {
                $asal = $lines[$share['line_id']];

                $baris = new OrderLine;
                $baris->forceFill([
                    'order_id' => $sibling->id,
                    'sku' => $asal->sku,
                    'urutan' => $urutan + 1,
                    'ordered_unit' => $asal->satuan_dasar_snapshot,
                    'ordered_qty' => $share['qty_base'],
                    'qty_per_ctn_snapshot' => $asal->qty_per_ctn_snapshot,
                    'satuan_dasar_snapshot' => $asal->satuan_dasar_snapshot,
                    'qty_base' => $share['qty_base'],
                ])->save();
            }

            OrderEvent::create([
                'order_id' => $sibling->id,
                'from_status' => OrderStatus::Draft,
                'to_status' => OrderStatus::Submitted,
                'actor_id' => null,
                'alasan' => "Pecahan dari {$parent->nomor} — stok dikirim dari {$warehouse->kode}.",
                'meta' => ['split_parent' => $parent->nomor],
            ]);

            return $sibling;
        });
    }

    /**
     * Bill the customer: issue the invoice.
     *
     * This is the moment the order becomes money owed, so it happens in the
     * same transaction as the transition — an order sitting at
     * `awaiting_payment` with no invoice would be a bill nobody can pay, and
     * it is exactly the state the AR queues and the buyer portal read from.
     *
     * Idempotent: re-running returns the existing invoice rather than
     * billing twice.
     *
     * A null actor means the system moved it — the stale-order sweep does.
     */
    public function awaitPayment(Order $order, ?User $actor = null, ?string $catatan = null): Order
    {
        $this->assertCan($order, OrderStatus::AwaitingPayment);

        return $this->inRegion($order, fn () => DB::transaction(function () use ($order, $actor, $catatan) {
            $invoice = $this->invoices->issueFor($order, $actor);

            return $this->transition(
                $order,
                OrderStatus::AwaitingPayment,
                $actor,
                $catatan,
                meta: [
                    'invoice_id' => $invoice->id,
                    'invoice_nomor' => $invoice->nomor,
                    'total_rupiah' => $invoice->total_rupiah,
                    'due_date' => $invoice->due_date->toDateString(),
                ],
            );
        }));
    }

    /**
     * Mark an order paid.
     *
     * Only invoice settlement in the payment ledger calls this — never a
     * controller responding to a user action, and never a browser redirect.
     * `$actor` is null because the actor is the money arriving; the invoice
     * id in meta ties the transition to the entries that settled it.
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

        return $this->inRegion($order, fn () => DB::transaction(function () use ($order, $actor, $catatan) {
            $movements = $this->stock->shipOrder($order, $actor);

            /*
             * Dr HPP / Cr Persediaan, at the cost frozen onto the movements a
             * line above. Posted from here rather than from inside the stock
             * ledger because this is the transaction that owns the shipment,
             * and because cost of sales is an accounting consequence of an
             * order shipping rather than of stock moving — a transfer between
             * warehouses moves stock and costs nothing.
             */
            $this->poster->shipmentCosted($order, $movements, $actor);

            return $this->transition(
                $order,
                OrderStatus::Shipped,
                $actor,
                $catatan,
                fn (Order $order) => $order->shipped_at = now(),
                meta: ['stock_movement_ids' => array_map(fn ($m) => $m->id, $movements)],
            );
        }));
    }

    /**
     * A null actor means the system completed it — settlement does, when the
     * last rupiah lands on a shipped order's invoice. "Finished" in the
     * organisation's words means paid, and nobody should have to click a
     * button to say so after the money already has.
     */
    public function complete(Order $order, ?User $actor, ?string $catatan = null): Order
    {
        return $this->transition($order, OrderStatus::Completed, $actor, $catatan, function (Order $order) {
            $order->completed_at = now();
        });
    }

    /**
     * Rejecting a confirmed order hands its reserved stock back.
     *
     * A null actor means the system rejected it — the stale-reservation sweep
     * does that for orders confirmed but never billed.
     */
    public function reject(Order $order, ?User $actor, string $alasan): Order
    {
        $this->assertCan($order, OrderStatus::Rejected);

        // The sweep rejects with no actor; a person rejecting sits in the
        // same seat as a person approving — it is the same credit decision
        // with the other answer.
        if ($actor !== null) {
            $this->assertMayApprove($order, $actor);
        }

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
    /**
     * Approval is a seat, not a permission alone.
     *
     * The reorganisation's rule: every order waits for the marketing in
     * charge of that customer. Their approval is the credit decision — the
     * moment goods are promised and the total becomes the customer's debt —
     * so it belongs to the person who answers for that customer's balance,
     * not to whichever colleague happened to open the queue.
     *
     * Owner passes both checks: they are the escape hatch for a customer
     * whose marketing is on leave, or has not been assigned yet.
     */
    private function assertMayApprove(Order $order, User $actor): void
    {
        if (! $actor->role()->canApproveOrders()) {
            throw new \DomainException(
                'Menyetujui order adalah keputusan kredit — hanya marketing '
                .'penanggung jawab pelanggan (atau pemilik) yang bisa.'
            );
        }

        if ($actor->role() !== Role::Marketing) {
            return;
        }

        $marketingId = $order->company->marketing_user_id;

        if ($marketingId === null) {
            throw new \DomainException(
                "Pelanggan {$order->company->nama} belum punya marketing penanggung jawab. "
                .'Minta pemilik menugaskan tim dulu di halaman pelanggan.'
            );
        }

        if ((int) $marketingId !== (int) $actor->getKey()) {
            throw new \DomainException(
                'Order ini milik pelanggan yang diurus marketing lain — bukan Anda. '
                .'Yang menyetujui harus marketing penanggung jawabnya.'
            );
        }
    }

    /**
     * Submit and, when the submitter holds the approval seat, approve in the
     * same breath.
     *
     * The organisation's asymmetry, made explicit: a salesperson's order
     * waits in pending exactly like the customer's own, but the marketing in
     * charge placing an order *is* the approval — asking them to click
     * approve on their own submission a second later would be ceremony, not
     * control. Two logged events still land, submit then confirm, so the
     * history reads the same either way.
     *
     * Falls back to plain submission for everyone else.
     */
    public function submitAndMaybeApprove(Order $order, User $actor, ?string $catatan = null): Order
    {
        $order = $this->submit($order, $actor, $catatan);

        $mayApprove = $actor->role()->canApproveOrders()
            && ($actor->role() !== Role::Marketing
                || (int) $order->company->marketing_user_id === (int) $actor->getKey());

        if ($mayApprove) {
            $order = $this->confirm($order, $actor, $catatan);
        }

        return $order;
    }

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

        $lines = $order->lines()->with('product')->get();

        /*
         * Read the price list once for the whole order, not once per line.
         *
         * A 20-line order used to run ~80 queries here — and this runs inside
         * the confirming transaction, the one already holding stock row locks,
         * so every one of those queries was time another confirmation spent
         * blocked.
         *
         * Each line still resolves on its own quantity: two lines can carry the
         * same SKU at different quantities, and they may land on different
         * quantity breaks.
         */
        $this->prices->prime($company, $lines->pluck('sku')->all(), $pricedOn);

        foreach ($lines as $line) {
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
     * There are three kinds of actor, and the event row keeps them apart: a
     * staff user, a buyer in the portal, or nobody at all — the scheduled sweep
     * and invoice settlement, where the actor is a clock or the money itself.
     *
     * @param  (callable(Order): void)|null  $mutate
     * @param  array<string, mixed>  $meta
     */
    /**
     * Run a mutation pinned to the order's own region.
     *
     * Marketing works open-to-all — they have no region — so a request of
     * theirs arrives here unpinned, and the scoped rows a transition creates
     * would have nowhere to file themselves. The order knows its region;
     * every write about the order belongs in that region's books, whoever
     * clicked. Harmless when already pinned: within() nests and restores.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inRegion(Order $order, callable $work): mixed
    {
        return app(RegionContext::class)->within((int) $order->region_id, $work);
    }

    private function transition(
        Order $order,
        OrderStatus $to,
        ?User $actor,
        ?string $alasan = null,
        ?callable $mutate = null,
        array $meta = [],
        ?CustomerUser $customerActor = null,
    ): Order {
        $from = $order->status;

        $this->assertCan($order, $to);

        return $this->inRegion($order, fn () => DB::transaction(function () use ($order, $from, $to, $actor, $alasan, $mutate, $meta, $customerActor) {
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
                'customer_actor_id' => $customerActor?->id,
                'alasan' => $alasan,
                'meta' => $meta === [] ? null : $meta,
            ]);

            $this->audit->log(
                action: 'order_transition',
                subject: $order,
                oldValue: ['status' => $from->value],
                newValue: array_filter([
                    'status' => $to->value,
                    // The audit log's actor_id is a staff user by definition.
                    // A buyer acting on their own order is recorded here rather
                    // than left looking like the system did it.
                    'customer_actor_id' => $customerActor?->id,
                    'customer_actor_email' => $customerActor?->email,
                ], fn ($v) => $v !== null),
                actor: $actor,
                alasan: $alasan,
            );

            return $order;
        }));
    }

    private function assertCan(Order $order, OrderStatus $to): void
    {
        if (! $order->status->canTransitionTo($to)) {
            throw new IllegalTransitionException($order->status, $to);
        }
    }
}
