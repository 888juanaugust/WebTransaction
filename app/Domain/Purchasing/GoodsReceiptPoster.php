<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\GoodsReceipt;
use App\Models\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Posts a goods receipt: stock arrives, and the average cost moves.
 *
 * One transaction. Either every line lands in the ledger and the document is
 * marked posted, or none of it happened — a half-posted receipt would leave
 * stock on the shelf that the valuation does not know it paid for.
 *
 * Posting is terminal. A posted receipt is never edited and never deleted, for
 * the same reason a stock movement is not: it is the evidence behind a number
 * on a balance sheet. Mistakes are corrected with an opposing document.
 */
class GoodsReceiptPoster
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<StockMovement>
     */
    public function post(GoodsReceipt $receipt, User $actor): array
    {
        if (! $actor->role()->canRecordPurchases()) {
            throw new DomainException('Anda tidak berhak memposting penerimaan barang.');
        }

        return DB::transaction(function () use ($receipt, $actor) {
            /*
             * Re-read under a lock and re-check the status inside the
             * transaction. Two people hitting Posting on the same draft is not
             * hypothetical — it is what happens when the first click looks
             * slow, and posting twice would double the stock and the value.
             */
            $locked = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if ($locked->isPosted()) {
                throw new DomainException("Penerimaan {$locked->nomor} sudah diposting.");
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Penerimaan {$locked->nomor} tidak punya baris.");
            }

            $movements = [];
            $total = 0;

            /*
             * Deterministic order, by SKU, for the same reason order
             * confirmation sorts its lines: two receipts touching the same two
             * SKUs must take their locks in the same sequence or they deadlock
             * against each other.
             */
            foreach ($lines->sortBy('sku')->values() as $line) {
                if ($line->qty_base <= 0) {
                    throw new DomainException(
                        "Baris {$line->sku} tidak punya jumlah dalam satuan dasar."
                    );
                }

                if ($line->line_value_rupiah < 0) {
                    throw new DomainException("Baris {$line->sku} punya nilai negatif.");
                }

                $movements[] = $this->stock->record(
                    sku: $line->sku,
                    warehouseId: $locked->warehouse_id,
                    qtySigned: $line->qty_base,
                    reason: MovementReason::Penerimaan,
                    referenceType: GoodsReceipt::class,
                    referenceId: (string) $locked->id,
                    actor: $actor,
                    valueRupiah: $line->line_value_rupiah,
                );

                $total += $line->line_value_rupiah;
            }

            $locked->forceFill([
                'status' => GoodsReceipt::STATUS_POSTED,
                'total_value_rupiah' => $total,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'goods_receipt_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'supplier_id' => $locked->supplier_id,
                    'warehouse_id' => $locked->warehouse_id,
                    'baris' => $lines->count(),
                    'qty_base' => (int) $lines->sum('qty_base'),
                    'total_value_rupiah' => $total,
                ],
                actor: $actor,
            );

            $receipt->refresh();

            return $movements;
        });
    }
}
