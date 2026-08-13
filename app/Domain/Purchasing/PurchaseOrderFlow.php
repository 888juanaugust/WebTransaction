<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Audit\AuditLogger;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Every purchase order transition, in one place.
 *
 * Same shape as OrderStateMachine on the sell side: nothing else writes
 * `status`, every move is checked against the enum rather than against an
 * ad-hoc condition, and every move is logged with an actor.
 */
class PurchaseOrderFlow
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Send the order to the supplier. This is what locks the lines.
     */
    public function send(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $this->assertMayAct($actor);

        return DB::transaction(function () use ($po, $actor) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);

            $this->assertTransition($locked, PurchaseOrderStatus::Dikirim);

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("PO {$locked->nomor} tidak punya baris.");
            }

            foreach ($lines as $line) {
                if ($line->qty_base <= 0) {
                    throw new DomainException("Baris {$line->sku} tidak punya jumlah dalam satuan dasar.");
                }
            }

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Dikirim,
                'total_value_rupiah' => (int) $lines->sum('line_value_rupiah'),
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'purchase_order_sent',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'supplier_id' => $locked->supplier_id,
                    'baris' => $lines->count(),
                    'total_value_rupiah' => $locked->total_value_rupiah,
                ],
                actor: $actor,
            );

            return $po->refresh();
        });
    }

    /**
     * Close an order: everything expected has arrived, or nothing more will.
     *
     * Closing short is legitimate and common — a supplier discontinues a part
     * mid-order — so this does not require full delivery. It records what was
     * still outstanding at the moment it was closed, because that number is the
     * only evidence left afterwards.
     */
    public function close(PurchaseOrder $po, User $actor, ?string $alasan = null): PurchaseOrder
    {
        $this->assertMayAct($actor);

        return DB::transaction(function () use ($po, $actor, $alasan) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);

            $this->assertTransition($locked, PurchaseOrderStatus::Selesai);

            $outstanding = $locked->lines()->get()
                ->reject(fn (PurchaseOrderLine $line) => $line->outstandingQty() <= 0)
                ->mapWithKeys(fn (PurchaseOrderLine $line) => [$line->sku => $line->outstandingQty()])
                ->all();

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Selesai,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'purchase_order_closed',
                subject: $locked,
                newValue: array_filter([
                    'nomor' => $locked->nomor,
                    'belum_diterima' => $outstanding ?: null,
                    'alasan' => $alasan,
                ]),
                actor: $actor,
            );

            return $po->refresh();
        });
    }

    /**
     * Cancel. Allowed until goods have actually arrived — after that the order
     * has to be closed instead, because cancelling a document that stock was
     * received against would leave the receipt pointing at nothing.
     */
    public function cancel(PurchaseOrder $po, User $actor, string $alasan): PurchaseOrder
    {
        $this->assertMayAct($actor);

        return DB::transaction(function () use ($po, $actor, $alasan) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);

            $this->assertTransition($locked, PurchaseOrderStatus::Dibatalkan);

            $received = (int) $locked->lines()->sum('qty_base_received');

            if ($received > 0) {
                throw new DomainException(
                    "PO {$locked->nomor} sudah menerima barang, jadi harus diselesaikan, bukan dibatalkan."
                );
            }

            $locked->forceFill([
                'status' => PurchaseOrderStatus::Dibatalkan,
                'alasan_batal' => $alasan,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'purchase_order_cancelled',
                subject: $locked,
                newValue: ['nomor' => $locked->nomor, 'alasan' => $alasan],
                actor: $actor,
            );

            return $po->refresh();
        });
    }

    /**
     * Record what a posted goods receipt delivered against its purchase order.
     *
     * Called from inside GoodsReceiptPoster's transaction, so the received
     * quantity and the stock movement land together or not at all.
     *
     * The cached `qty_base_received` is reconstructible by summing the receipt
     * lines that point at each PO line — the same relationship stock_levels has
     * to stock_movements, and reconcilable the same way.
     */
    public function registerReceipt(GoodsReceipt $receipt, User $actor): void
    {
        $lines = $receipt->lines()->whereNotNull('purchase_order_line_id')->get();

        if ($lines->isEmpty()) {
            return;
        }

        // Deterministic lock order, as everywhere else that takes more than one.
        foreach ($lines->sortBy('purchase_order_line_id') as $line) {
            $poLine = PurchaseOrderLine::query()
                ->lockForUpdate()
                ->findOrFail($line->purchase_order_line_id);

            $poLine->qty_base_received += $line->qty_base;
            $poLine->save();
        }

        $po = $receipt->purchaseOrder;

        if ($po === null) {
            return;
        }

        /*
         * Close the order once everything has arrived.
         *
         * Without this every completed order sits in `dikirim` forever and the
         * "still expected" list stops meaning anything — which is the one
         * question the document exists to answer.
         */
        $po->refresh()->load('lines');

        if ($po->status === PurchaseOrderStatus::Dikirim && $po->isFullyReceived()) {
            $this->close($po, $actor, 'Seluruh barang telah diterima.');
        }
    }

    private function assertMayAct(User $actor): void
    {
        if (! $actor->role()->canRecordPurchases()) {
            throw new DomainException('Anda tidak berhak mengubah pesanan pembelian.');
        }
    }

    private function assertTransition(PurchaseOrder $po, PurchaseOrderStatus $next): void
    {
        if (! $po->status->canTransitionTo($next)) {
            throw new DomainException(sprintf(
                'PO %s berstatus %s, tidak bisa menjadi %s.',
                $po->nomor,
                $po->status->label(),
                $next->label(),
            ));
        }
    }
}
