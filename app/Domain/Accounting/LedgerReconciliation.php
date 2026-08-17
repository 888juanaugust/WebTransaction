<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Billing\OutstandingReceivables;
use App\Domain\Money;
use App\Domain\Purchasing\SupplierLedger;
use App\Domain\Stock\InventoryValuation;
use App\Models\GoodsReceiptLine;
use App\Models\LandedCost;
use App\Models\PurchaseReturn;
use App\Models\SupplierBillLine;

/**
 * Does the ledger still agree with the subledgers it was built from?
 *
 * A trial balance proves the ledger is internally consistent. It cannot prove
 * the postings were right — a rule that credits the wrong account balances
 * perfectly and is still wrong. What catches that is the control account: the
 * ledger's Piutang Usaha has to equal the sum of open customer invoices, and
 * if it does not, one of the rules in DocumentPoster is wrong.
 *
 * This is the check that would have caught every accounting bug I can think of
 * for this system, and it is cheap enough to put on a dashboard and run
 * nightly. The subledgers remain authoritative for operations — a buyer's
 * credit is checked against invoices, not against the ledger — so a drift here
 * is a bookkeeping defect rather than a business one, which is exactly the
 * kind that goes unnoticed for a quarter.
 */
class LedgerReconciliation
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly InventoryValuation $valuation,
        private readonly SupplierLedger $suppliers,
        private readonly OutstandingReceivables $receivables,
    ) {}

    /**
     * @return list<ControlAccountCheck>
     */
    public function checks(): array
    {
        return [
            new ControlAccountCheck(
                kode: AccountCode::PIUTANG_USAHA,
                nama: 'Piutang Usaha',
                buku: $this->ledger->balanceOf(AccountCode::PIUTANG_USAHA),
                subledger: $this->receivables->total(),
                sumber: 'Faktur pelanggan, dikurangi pembayaran dan nota kredit',
            ),
            new ControlAccountCheck(
                kode: AccountCode::UTANG_USAHA,
                nama: 'Utang Usaha',
                buku: $this->ledger->balanceOf(AccountCode::UTANG_USAHA),
                subledger: $this->suppliers->totalPayable(),
                sumber: 'Tagihan pemasok, dikurangi pembayaran dan retur pembelian',
            ),
            new ControlAccountCheck(
                kode: AccountCode::PERSEDIAAN,
                nama: 'Persediaan',
                buku: $this->ledger->balanceOf(AccountCode::PERSEDIAAN),
                subledger: $this->valuation->totalValue(),
                sumber: 'Nilai persediaan rata-rata bergerak (product_costs)',
            ),
            new ControlAccountCheck(
                kode: AccountCode::UTANG_BELUM_DITAGIH,
                nama: 'Utang Belum Ditagih',
                buku: $this->ledger->balanceOf(AccountCode::UTANG_BELUM_DITAGIH),
                subledger: $this->receivedNotBilled(),
                sumber: 'Barang diterima yang belum ada tagihannya, dikurangi yang sudah diretur',
            ),
            new ControlAccountCheck(
                kode: AccountCode::BIAYA_BELUM_DIALOKASIKAN,
                nama: 'Biaya Perolehan Belum Dialokasikan',
                buku: $this->ledger->balanceOf(AccountCode::BIAYA_BELUM_DIALOKASIKAN),
                subledger: $this->unallocatedCharges(),
                sumber: 'Biaya angkut dan bea yang belum dibebankan ke barangnya',
            ),
        ];
    }

    public function isClean(): bool
    {
        foreach ($this->checks() as $check) {
            if (! $check->agrees()) {
                return false;
            }
        }

        return $this->ledger->isBalanced();
    }

    /** @return list<ControlAccountCheck> */
    public function discrepancies(): array
    {
        return array_values(array_filter($this->checks(), fn (ControlAccountCheck $c) => ! $c->agrees()));
    }

    /**
     * Goods on the shelf that nobody has billed us for.
     *
     * Receipt value less the value of the receipt lines a posted bill points
     * at — cleared at the receipt's own cost, which is what the posting rule
     * clears, so a price variance shows up as a variance rather than as a
     * permanent residue here.
     */
    private function receivedNotBilled(): int
    {
        $received = (int) GoodsReceiptLine::query()
            ->join('goods_receipts', 'goods_receipt_lines.goods_receipt_id', '=', 'goods_receipts.id')
            ->where('goods_receipts.status', 'posted')
            ->sum('goods_receipt_lines.line_value_rupiah');

        /*
         * The billed side is valued at the *receipt's* cost for the quantity
         * billed, not at what the supplier charged. mulDiv per row is why this
         * is a collection walk rather than one SUM: integer rounding has to
         * happen the same way it happened when the entry was posted, or the
         * two disagree by a rupiah and the check cries wolf.
         */
        $billed = 0;

        SupplierBillLine::query()
            ->join('supplier_bills', 'supplier_bill_lines.supplier_bill_id', '=', 'supplier_bills.id')
            ->join('goods_receipt_lines', 'supplier_bill_lines.goods_receipt_line_id', '=', 'goods_receipt_lines.id')
            ->whereNotNull('supplier_bills.posted_at')
            ->where('supplier_bills.status', '!=', 'void')
            ->where('goods_receipt_lines.qty_base', '>', 0)
            ->select([
                'supplier_bill_lines.qty_base AS billed_qty',
                'goods_receipt_lines.qty_base AS received_qty',
                'goods_receipt_lines.line_value_rupiah AS received_value',
            ])
            ->chunk(500, function ($rows) use (&$billed) {
                foreach ($rows as $row) {
                    $billed += Money::mulDiv(
                        (int) $row->received_value,
                        (int) $row->billed_qty,
                        (int) $row->received_qty,
                    );
                }
            });

        /*
         * Goods lines with no receipt behind them push the account contra —
         * billed before delivery — and the posting rule accrues the full
         * amount, so the subledger figure has to subtract it too.
         *
         * Cost lines are excluded because they never came here: freight and
         * duty go to the clearing account, which has its own check below.
         */
        $billedWithoutReceipt = (int) SupplierBillLine::query()
            ->join('supplier_bills', 'supplier_bill_lines.supplier_bill_id', '=', 'supplier_bills.id')
            ->whereNull('supplier_bill_lines.goods_receipt_line_id')
            ->where('supplier_bill_lines.jenis', '!=', SupplierBillLine::JENIS_BIAYA)
            ->whereNotNull('supplier_bills.posted_at')
            ->where('supplier_bills.status', '!=', 'void')
            ->sum('supplier_bill_lines.line_total_rupiah');

        /*
         * Goods sent back before anybody billed us for them. The receipt
         * accrued them here and the return unwinds exactly that, at the same
         * receipt cost — the return's own snapshot, not recomputed, for the
         * same rounding reason as the billed side above.
         *
         * Returns of goods that *had* been billed do not appear: those came
         * off Utang Usaha, which has its own check.
         */
        $returnedUnbilled = (int) PurchaseReturn::query()
            ->posted()
            ->sum('nilai_belum_ditagih_rupiah');

        return $received - $billed - $billedWithoutReceipt - $returnedUnbilled;
    }

    /**
     * Charges billed to us that nobody has spread over the goods yet.
     *
     * Every posted cost line, less the ones a posted allocation has drained.
     * The allocation always moves the whole charge — it splits it between
     * inventory and cost of sales, but never leaves part of it behind — so
     * this is a plain difference of two sums rather than an apportionment.
     *
     * Zero is the healthy answer, and it is the number the queue on the
     * dashboard counts.
     */
    private function unallocatedCharges(): int
    {
        $billed = (int) SupplierBillLine::query()
            ->join('supplier_bills', 'supplier_bill_lines.supplier_bill_id', '=', 'supplier_bills.id')
            ->where('supplier_bill_lines.jenis', SupplierBillLine::JENIS_BIAYA)
            ->whereNotNull('supplier_bills.posted_at')
            ->where('supplier_bills.status', '!=', 'void')
            ->sum('supplier_bill_lines.line_total_rupiah');

        $allocated = (int) LandedCost::query()
            ->where('status', LandedCost::STATUS_POSTED)
            ->sum('amount_rupiah');

        return $billed - $allocated;
    }
}
