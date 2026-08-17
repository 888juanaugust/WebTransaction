<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Audit\AuditLogger;
use App\Models\StockTransfer;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Posts a transfer: goods leave one warehouse and arrive at another.
 *
 * No journal entry, and that is not an omission. `product_costs` is keyed by
 * SKU rather than by warehouse, so the total value of the inventory is
 * identical before and after — there is nothing for double entry to say. The
 * ledger's Persediaan balance is untouched, and the reconciliation proves it.
 *
 * What the document does have to guarantee is that the quantity and value that
 * leave are exactly what arrive, which is StockLedger::transfer()'s job. This
 * class is the paperwork around it: one transaction, all lines or none, and
 * posting is terminal.
 */
class StockTransferPoster
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly AuditLogger $audit,
    ) {}

    public function post(StockTransfer $transfer, User $actor): StockTransfer
    {
        if (! $actor->role()->canTransferStock()) {
            throw new DomainException('Anda tidak berhak memposting transfer gudang.');
        }

        return DB::transaction(function () use ($transfer, $actor) {
            /*
             * Re-read under a lock and re-check the status inside the
             * transaction. Two people hitting Posting on the same draft is
             * what happens when the first click looks slow, and posting twice
             * would move the goods twice.
             */
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($locked->isPosted()) {
                throw new DomainException("Transfer {$locked->nomor} sudah diposting.");
            }

            if ($locked->from_warehouse_id === $locked->to_warehouse_id) {
                throw new DomainException('Transfer harus antara dua gudang yang berbeda.');
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Transfer {$locked->nomor} tidak punya baris.");
            }

            $total = 0;

            // Sorted by SKU, like every other multi-line stock operation here:
            // two documents touching the same two SKUs must take their locks
            // in the same sequence or they deadlock against each other.
            foreach ($lines->sortBy('sku')->values() as $line) {
                if ($line->qty_base <= 0) {
                    throw new DomainException("Baris {$line->sku} tidak punya jumlah.");
                }

                [$out] = $this->stock->transfer(
                    sku: $line->sku,
                    fromWarehouseId: $locked->from_warehouse_id,
                    toWarehouseId: $locked->to_warehouse_id,
                    qtyBase: (int) $line->qty_base,
                    referenceType: StockTransfer::class,
                    referenceId: (string) $locked->id,
                    actor: $actor,
                    catatan: $line->catatan,
                );

                $value = $out->value_rupiah === null ? null : -(int) $out->value_rupiah;

                $line->forceFill([
                    'unit_cost_rupiah' => $out->unit_cost_rupiah,
                    'line_value_rupiah' => $value,
                ])->save();

                $total += $value ?? 0;
            }

            $locked->forceFill([
                'status' => StockTransfer::STATUS_POSTED,
                'total_value_rupiah' => $total,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'stock_transfer_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'from_warehouse_id' => $locked->from_warehouse_id,
                    'to_warehouse_id' => $locked->to_warehouse_id,
                    'baris' => $lines->count(),
                    'qty_base' => (int) $lines->sum('qty_base'),
                    'total_value_rupiah' => $total,
                ],
                actor: $actor,
                alasan: $locked->catatan,
            );

            return $transfer->refresh();
        });
    }
}
