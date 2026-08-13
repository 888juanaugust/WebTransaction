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
}
