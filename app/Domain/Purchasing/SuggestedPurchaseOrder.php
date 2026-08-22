<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Stock\ReorderAdvisor;
use App\Domain\Stock\ReorderSuggestion;
use App\Domain\Uom\Unit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turning the reorder list into a purchase order somebody can send.
 *
 * A worklist that ends in "now go and type all that into a different screen" is
 * a worklist people stop using, and the retyping is where the quantities drift
 * from the ones that were calculated.
 *
 * **It stops at draft, deliberately.** Every figure here is derived — from a
 * sales rate, an averaged lead time, and a cost from the last delivery — and
 * derived figures are a starting point for a buyer, not a commitment to a
 * supplier. The person sending it still has to look at it, and `send()` is
 * still the act that locks the lines.
 *
 * Creating the draft takes those parts straight off the reorder list, because
 * a draft counts as stock on order. That is the point: it is what stops the
 * same shortage being ordered twice by two people on the same morning.
 */
class SuggestedPurchaseOrder
{
    public function __construct(
        private readonly ReorderAdvisor $advisor,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    public function draftFor(
        Supplier $supplier,
        Warehouse $warehouse,
        User $actor,
        ?string $catatan = null,
    ): PurchaseOrder {
        if (! $actor->role()->canRecordPurchases()) {
            throw new DomainException('Anda tidak berhak membuat pesanan pembelian.');
        }

        $suggestions = array_values(array_filter(
            $this->advisor->forSupplier($supplier->id),
            fn (ReorderSuggestion $s) => $s->saranQtyCtn > 0,
        ));

        if ($suggestions === []) {
            throw new DomainException(
                "Tidak ada barang dari {$supplier->nama} yang perlu dipesan sekarang."
            );
        }

        return DB::transaction(function () use ($supplier, $warehouse, $actor, $catatan, $suggestions) {
            $po = PurchaseOrder::query()->create([
                'nomor' => $this->numbers->nextPurchaseOrderNumber(),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'tanggal_po' => now()->toDateString(),
                'catatan' => $catatan ?? 'Dibuat dari daftar titik pesan ulang.',
                'created_by' => $actor->id,
            ]);

            $costs = $this->lastCostPerBaseUnit(array_map(
                fn (ReorderSuggestion $s) => $s->sku,
                $suggestions,
            ));

            $total = 0;

            foreach ($suggestions as $i => $s) {
                /*
                 * Ordered in cartons, because that is the unit the suggestion
                 * was rounded to and the unit the supplier sells in. Storing
                 * the base quantity alongside it is invariant 5 — the line
                 * carries both the ordered unit and the resolved base figure.
                 */
                $perCarton = (int) round(($costs[$s->sku] ?? 0) * $s->qtyPerCtn);
                $lineValue = $perCarton * $s->saranQtyCtn;
                $total += $lineValue;

                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $po->id,
                    'sku' => $s->sku,
                    'urutan' => $i + 1,
                    'ordered_unit' => Unit::Ctn,
                    'ordered_qty' => $s->saranQtyCtn,
                    'qty_per_ctn_snapshot' => $s->qtyPerCtn,
                    'satuan_dasar_snapshot' => $s->satuanDasar,
                    'qty_base' => $s->saranQtyBase,
                    'unit_cost_rupiah' => $perCarton,
                    'line_value_rupiah' => $lineValue,
                    // A part never bought before has no last price, and a
                    // silent zero on a purchase order is a line somebody sends
                    // without noticing.
                    'catatan' => $perCarton === 0
                        ? 'Harga belum pernah tercatat — isi sebelum dikirim.'
                        : null,
                ]);
            }

            $po->forceFill(['total_value_rupiah' => $total])->save();

            $this->audit->log(
                action: 'purchase_order_drafted_from_reorder',
                subject: $po,
                newValue: [
                    'nomor' => $po->nomor,
                    'pemasok' => $supplier->nama,
                    'baris' => count($suggestions),
                    'total_value_rupiah' => $total,
                ],
                actor: $actor,
            );

            return $po->refresh();
        });
    }

    /**
     * What we last paid, per base unit.
     *
     * Per base unit rather than per carton, because the carton size can change
     * between deliveries — a supplier repacking from 12s to 10s would
     * otherwise turn a correct price into one that is 20% out. Derived from
     * the line value over the base quantity, which is right whichever unit the
     * delivery was counted in.
     *
     * Zero where a part has never been received. The buyer fills it in, and
     * the line says so rather than leaving a plausible-looking nothing.
     *
     * @param  list<string>  $skus
     * @return array<string, float>
     */
    private function lastCostPerBaseUnit(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $rows = DB::table('goods_receipt_lines')
            ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
            ->whereIn('goods_receipt_lines.sku', $skus)
            ->whereNotNull('goods_receipts.posted_at')
            ->where('goods_receipt_lines.qty_base', '>', 0)
            ->orderBy('goods_receipt_lines.sku')
            ->orderByDesc('goods_receipts.tanggal_terima')
            ->orderByDesc('goods_receipts.id')
            ->select([
                'goods_receipt_lines.sku',
                'goods_receipt_lines.line_value_rupiah',
                'goods_receipt_lines.qty_base',
            ])
            ->get();

        $costs = [];

        foreach ($rows as $row) {
            // Newest first per SKU, so the first one wins.
            $costs[(string) $row->sku] ??= (int) $row->line_value_rupiah / (int) $row->qty_base;
        }

        return $costs;
    }
}
