<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Models\GoodsReceiptLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierBillLine;

/**
 * Ordered against received against billed.
 *
 * This is the reason the three documents are worth having together rather than
 * separately. Any one of them alone is a claim; agreement between them is
 * evidence. The failures it catches are the ordinary ones, not exotic fraud:
 *
 *   - a short delivery nobody noticed, then billed in full
 *   - a price that moved between the quote and the invoice
 *   - the same delivery billed twice, on two of the supplier's documents
 *
 * It reports and does not block. A variance is usually a conversation with the
 * supplier, not an error — and a control that refuses to let people record what
 * actually happened gets worked around, which loses the record entirely.
 *
 * **Price variance is not posted to inventory.** Goods are valued at what the
 * receipt said they cost; if the bill later disagrees, the stock valuation is
 * left alone and the difference goes to Selisih Harga Pembelian in the general
 * ledger — an expense of this period rather than a silent restatement of the
 * balance sheet. This screen is where somebody sees it and rings the supplier;
 * DocumentPoster::supplierBillPosted is where it lands in the books.
 */
class ThreeWayMatch
{
    /**
     * @return list<MatchLine>
     */
    public function forPurchaseOrder(PurchaseOrder $po): array
    {
        $po->loadMissing('lines');

        /*
         * Billed quantity and value per PO line, in one query rather than one
         * per line: a bill line points at a receipt line, and a receipt line
         * points at the PO line.
         */
        $billed = SupplierBillLine::query()
            ->join('goods_receipt_lines', 'supplier_bill_lines.goods_receipt_line_id', '=', 'goods_receipt_lines.id')
            ->join('supplier_bills', 'supplier_bill_lines.supplier_bill_id', '=', 'supplier_bills.id')
            ->whereNotNull('goods_receipt_lines.purchase_order_line_id')
            ->where('supplier_bills.status', '!=', 'void')
            ->whereNotNull('supplier_bills.posted_at')
            ->groupBy('goods_receipt_lines.purchase_order_line_id')
            ->selectRaw(
                'goods_receipt_lines.purchase_order_line_id AS po_line_id, '
                .'SUM(supplier_bill_lines.qty_base) AS qty, '
                .'SUM(supplier_bill_lines.line_total_rupiah) AS value'
            )
            ->get()
            ->keyBy('po_line_id');

        // What the receipts actually valued the goods at, which is what went
        // into inventory — not the agreed PO price.
        $received = GoodsReceiptLine::query()
            ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
            ->whereNotNull('goods_receipt_lines.purchase_order_line_id')
            ->where('goods_receipts.status', 'posted')
            ->groupBy('goods_receipt_lines.purchase_order_line_id')
            ->selectRaw(
                'goods_receipt_lines.purchase_order_line_id AS po_line_id, '
                .'SUM(goods_receipt_lines.line_value_rupiah) AS value'
            )
            ->get()
            ->keyBy('po_line_id');

        return $po->lines->map(function (PurchaseOrderLine $line) use ($billed, $received) {
            $billedRow = $billed->get($line->id);
            $receivedRow = $received->get($line->id);

            return new MatchLine(
                sku: $line->sku,
                orderedQty: $line->qty_base,
                receivedQty: $line->qty_base_received,
                billedQty: (int) ($billedRow->qty ?? 0),
                orderedValue: $line->line_value_rupiah,
                receivedValue: (int) ($receivedRow->value ?? 0),
                billedValue: (int) ($billedRow->value ?? 0),
            );
        })->all();
    }

    /**
     * Only the lines that disagree — what a person actually needs to look at.
     *
     * @return list<MatchLine>
     */
    public function variancesFor(PurchaseOrder $po): array
    {
        return array_values(array_filter(
            $this->forPurchaseOrder($po),
            fn (MatchLine $line) => $line->hasVariance(),
        ));
    }
}
