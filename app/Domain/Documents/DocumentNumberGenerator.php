<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issues human-readable document numbers: SO-202608-0001, INV-202608-0001.
 *
 * The counter row is taken with SELECT ... FOR UPDATE inside the caller's
 * transaction, so two staff confirming at the same instant serialise here
 * rather than both being handed 0007. If the caller's transaction rolls back,
 * the number is released with it — which is why this is a counter table and
 * not a Postgres sequence. Sequences deliberately survive rollback, and an
 * invoice register with gaps is a question you have to answer to an auditor.
 */
class DocumentNumberGenerator
{
    public const SCOPE_ORDER = 'order';

    public const SCOPE_INVOICE = 'invoice';

    public const SCOPE_GOODS_RECEIPT = 'goods_receipt';

    public const SCOPE_PURCHASE_ORDER = 'purchase_order';

    public const SCOPE_SUPPLIER_BILL = 'supplier_bill';

    public const SCOPE_JOURNAL = 'journal';

    public const SCOPE_CREDIT_NOTE = 'credit_note';

    public const SCOPE_STOCK_TRANSFER = 'stock_transfer';

    public const SCOPE_STOCK_OPNAME = 'stock_opname';

    public const SCOPE_LANDED_COST = 'landed_cost';

    public const SCOPE_PURCHASE_RETURN = 'purchase_return';

    public const SCOPE_FAKTUR_EXPORT = 'faktur_export';

    /**
     * @param  string  $prefix  'SO' or 'INV'.
     */
    public function next(string $scope, string $prefix, ?DateTimeInterface $date = null): string
    {
        $date = $date ? Carbon::parse($date) : Carbon::now();
        $period = $date->format('Ym');

        $sequence = DB::transaction(function () use ($scope, $period) {
            // Create the period's counter on first use. insertOrIgnore so two
            // concurrent first-issues of a month don't collide on the unique
            // index — the loser simply reads the winner's row below.
            DB::table('document_counters')->insertOrIgnore([
                'scope' => $scope,
                'period' => $period,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = DB::table('document_counters')
                ->where('scope', $scope)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            DB::table('document_counters')
                ->where('id', $counter->id)
                ->update([
                    'next_value' => $counter->next_value + 1,
                    'updated_at' => now(),
                ]);

            return (int) $counter->next_value;
        });

        return sprintf('%s-%s-%04d', $prefix, $period, $sequence);
    }

    public function nextOrderNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_ORDER, 'SO', $date);
    }

    public function nextInvoiceNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_INVOICE, 'INV', $date);
    }

    /** Terima Barang: TB-202608-0001. */
    public function nextGoodsReceiptNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_GOODS_RECEIPT, 'TB', $date);
    }

    /** Pesanan Pembelian: PO-202608-0001. */
    public function nextPurchaseOrderNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_PURCHASE_ORDER, 'PO', $date);
    }

    /** Tagihan Pemasok: TP-202608-0001. */
    public function nextSupplierBillNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_SUPPLIER_BILL, 'TP', $date);
    }

    /** Transfer Gudang: TG-202608-0001. */
    public function nextStockTransferNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_STOCK_TRANSFER, 'TG', $date);
    }

    /** Stok Opname: SO is taken by sales orders, so OP-202608-0001. */
    public function nextStockOpnameNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_STOCK_OPNAME, 'OP', $date);
    }

    /** Biaya Perolehan: BP-202608-0001. */
    public function nextLandedCostNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_LANDED_COST, 'BP', $date);
    }

    /** Ekspor Faktur Pajak: EF-202608-0001. */
    public function nextFakturExportNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_FAKTUR_EXPORT, 'EF', $date);
    }

    /**
     * Retur Pembelian: RP-202608-0001.
     *
     * This number is what goes on the nota retur the supplier receives, so the
     * register has to read straight — which is why every document here counts
     * through a locked counter row rather than a sequence that survives
     * rollback.
     */
    public function nextPurchaseReturnNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_PURCHASE_RETURN, 'RP', $date);
    }

    /** Nota Kredit: NK-202608-0001. */
    public function nextCreditNoteNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_CREDIT_NOTE, 'NK', $date);
    }

    /**
     * Jurnal Umum: JU-202608-0001.
     *
     * Numbered by the date the entry belongs to, not the date it was typed —
     * a bill entered in September for August work carries an August number,
     * and the register for August reads in order.
     */
    public function nextJournalNumber(?DateTimeInterface $date = null): string
    {
        return $this->next(self::SCOPE_JOURNAL, 'JU', $date);
    }
}
