<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Money;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Domain\Tax\TaxCalculator;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockLevel;
use App\Models\SupplierBill;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a purchase return: the goods leave, and the supplier owes us.
 *
 * One transaction, and every figure is decided here rather than on the draft.
 * A draft carries quantities and an intention; posting turns them into money.
 *
 * Two things about this document are worth understanding before changing it.
 *
 * **The goods leave at the running average, not at what the receipt paid.**
 * That is not a shortcut — it is the stock ledger's rule, and StockLedger
 * refuses a stated value on an outbound movement precisely so that nobody can
 * decide after the fact what leaving stock is worth. Under moving-average
 * costing the average has moved since the delivery if anything arrived at a
 * different price, so what leaves the shelf and what the supplier credits are
 * genuinely two different numbers. The difference is a real gain or loss, and
 * it goes to Selisih Harga Pembelian — the account that already means "what
 * stock is carried at and what the supplier settles at are not the same".
 *
 * **The billed and unbilled parts go to different accounts.** A return of
 * goods the supplier has invoiced reduces Utang Usaha and reverses the input
 * VAT; a return of goods they have not invoiced unwinds Utang Belum Ditagih
 * and touches no tax, because there is no faktur pajak yet. Both happen on the
 * same delivery. Getting it wrong does not look wrong — it balances perfectly
 * and quietly breaks a control account, which is why it is computed here
 * rather than asked of whoever is typing.
 *
 * Posting is terminal, like every other money document here. A return issued
 * in error is corrected by receiving the goods back in, not by un-posting.
 */
class PurchaseReturnPoster
{
    public function __construct(
        private readonly PurchaseReturnIssuer $issuer,
        private readonly TaxCalculator $tax,
        private readonly StockLedger $stock,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function post(PurchaseReturn $return, User $actor): PurchaseReturn
    {
        if (! $actor->role()->canReturnToSupplier()) {
            throw new DomainException('Anda tidak berhak memposting retur pembelian.');
        }

        return DB::transaction(function () use ($return, $actor) {
            /*
             * Re-read under a lock and re-check the status inside the
             * transaction. Two people hitting Posting on the same draft is
             * what happens when the first click looks slow, and posting twice
             * would take the goods off the shelf twice and credit us twice.
             */
            $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->isPosted()) {
                throw new DomainException("Retur {$locked->nomor} sudah diposting.");
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Retur {$locked->nomor} tidak punya baris.");
            }

            $receipt = $locked->goodsReceipt()->firstOrFail();

            $returnable = collect($this->issuer->returnable($receipt))
                ->keyBy(fn (ReturnableLine $line) => $line->receiptLine->id);

            $ditagih = 0;
            $belumDitagih = 0;
            $dpp = 0;
            $ppn = 0;
            $persediaan = 0;
            $creditable = true;

            /*
             * Sorted by SKU, for the same reason order confirmation is: two
             * documents touching the same two SKUs must take their stock locks
             * in the same sequence or they deadlock against each other.
             */
            foreach ($lines->sortBy('sku')->values() as $line) {
                $source = $this->settleLine($locked, $line, $returnable, $actor);

                $ditagih += (int) $line->nilai_ditagih_rupiah;
                $belumDitagih += (int) $line->nilai_belum_ditagih_rupiah;
                $dpp += (int) $line->dpp_rupiah;
                $ppn += (int) $line->ppn_rupiah;
                $persediaan += (int) $line->nilai_persediaan_rupiah;

                // Any billed line whose bill had no faktur pajak makes the
                // whole reversal non-creditable; see the note on the header.
                if ($line->qty_ditagih > 0 && ! $source->inputVatCreditable) {
                    $creditable = false;
                }
            }

            $total = $ditagih + $ppn;

            $locked->forceFill([
                'nilai_ditagih_rupiah' => $ditagih,
                'nilai_belum_ditagih_rupiah' => $belumDitagih,
                'dpp_rupiah' => $dpp,
                'ppn_rupiah' => $ppn,
                'total_rupiah' => $total,
                'nilai_persediaan_rupiah' => $persediaan,
                // The balancing figure, and the reason the entry balances at
                // all. Positive is a loss: stock left carrying more than the
                // supplier is giving back.
                'selisih_rupiah' => $persediaan - $ditagih - $belumDitagih,
                'kode_transaksi' => $ppn > 0 ? $this->tax->kodeTransaksi() : null,
                'status' => PurchaseReturn::STATUS_POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            $this->poster->purchaseReturnPosted($locked->refresh(), $actor, $creditable);

            $this->resettleBills($locked);

            $this->audit->log(
                action: 'purchase_return_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'supplier_id' => $locked->supplier_id,
                    'goods_receipt_id' => $locked->goods_receipt_id,
                    'qty_base' => (int) $lines->sum('qty_base'),
                    'nilai_ditagih_rupiah' => $ditagih,
                    'nilai_belum_ditagih_rupiah' => $belumDitagih,
                    'ppn_rupiah' => $ppn,
                    'total_rupiah' => $total,
                    'nilai_persediaan_rupiah' => $persediaan,
                    'selisih_rupiah' => (int) $locked->selisih_rupiah,
                ],
                actor: $actor,
                alasan: $locked->alasan,
            );

            return $return->refresh();
        });
    }

    /**
     * Settle one line: check it, move the stock, write the snapshots.
     *
     * @param  Collection<int, ReturnableLine>  $returnable
     */
    private function settleLine(
        PurchaseReturn $return,
        PurchaseReturnLine $line,
        Collection $returnable,
        User $actor,
    ): ReturnableLine {
        $source = $returnable->get($line->goods_receipt_line_id);

        if ($source === null) {
            throw new DomainException(
                "Baris {$line->sku} tidak termasuk dalam penerimaan yang diretur."
            );
        }

        $qty = (int) $line->qty_base;

        if ($qty <= 0) {
            throw new DomainException("Baris {$line->sku} tidak punya jumlah retur.");
        }

        if ($qty > $source->remainingQty()) {
            throw new DomainException(sprintf(
                'Retur %s melebihi yang pernah diterima: diminta %d, tersisa %d dari %d yang diterima.',
                $line->sku,
                $qty,
                $source->remainingQty(),
                $source->receivedQty,
            ));
        }

        $this->assertOnTheShelf($return, $line->sku, $qty);

        [$qtyDitagih, $qtyBelumDitagih] = $source->splitFor($qty);

        /*
         * One delivery line invoiced across two bills. Apportioning the credit
         * between them would be a guess about which invoice the supplier means
         * to credit, and a wrong guess leaves one bill chased for goods that
         * went back while the other is under-paid. Refusing says so out loud
         * and leaves a person to settle it, which is the honest outcome for a
         * case this rare.
         */
        if ($qtyDitagih > 0 && $source->isSplitAcrossBills()) {
            throw new DomainException(sprintf(
                'Baris %s ditagih di lebih dari satu tagihan pemasok, jadi retur ini '
                .'tidak bisa menentukan tagihan mana yang dikredit. Selesaikan lewat '
                .'jurnal manual dan nota kredit pemasok.',
                $line->sku,
            ));
        }

        /*
         * The billed part comes back at what the supplier charged, because
         * that is what they will credit. The unbilled part comes back at what
         * the receipt valued it, because that is exactly what the receipt
         * accrued and this unwinds it.
         */
        $nilaiDitagih = $source->billedValueFor($qtyDitagih);
        $nilaiBelumDitagih = $source->receiptCostFor($qtyBelumDitagih);

        // Tax on the billed part only. There is no faktur pajak behind goods
        // nobody has invoiced, so there is nothing to reverse.
        $breakdown = $this->tax->forLine($nilaiDitagih);

        $movement = $this->stock->record(
            sku: $line->sku,
            warehouseId: $return->warehouse_id,
            qtySigned: -$qty,
            reason: MovementReason::ReturPembelian,
            referenceType: PurchaseReturn::class,
            referenceId: (string) $return->id,
            actor: $actor,
            catatan: $return->alasan,
        );

        /*
         * What actually left, from the movement rather than recomputed.
         * Persediaan is a control account: recomputing quantity times average
         * rounds a second time and leaves the ledger and the valuation a
         * rupiah apart, which is a reconciliation that fails every day until
         * somebody chases it.
         *
         * Null for stock that was never costed — the opening balances that
         * predate the costing layer. Nothing leaves inventory value in that
         * case, which is the honest answer rather than a zero pretending to be
         * a measurement.
         */
        $nilaiPersediaan = $movement->value_rupiah === null
            ? 0
            : -(int) $movement->value_rupiah;

        $line->forceFill([
            'supplier_bill_id' => $qtyDitagih > 0 ? $source->billId() : null,
            'qty_ditagih' => $qtyDitagih,
            'nilai_ditagih_rupiah' => $nilaiDitagih,
            'nilai_belum_ditagih_rupiah' => $nilaiBelumDitagih,
            'dpp_rupiah' => $breakdown->dpp,
            'ppn_rupiah' => $breakdown->ppn,
            'nilai_persediaan_rupiah' => $nilaiPersediaan,
            'unit_cost_rupiah' => Money::mulDiv($nilaiPersediaan, 1, $qty),
            'deskripsi' => $line->deskripsi ?? $source->deskripsi,
            'qty_per_ctn_snapshot' => $line->qty_per_ctn_snapshot
                ?: ($source->receiptLine->qty_per_ctn_snapshot ?: 1),
        ])->save();

        return $source;
    }

    /**
     * A bill the return has fully covered stops being chased.
     *
     * A delivery sent back in full before anybody paid for it leaves an
     * invoice with nothing owing on it, and the only thing standing between
     * that and somebody paying it anyway is this: `status` follows the
     * arithmetic rather than whoever acted last, exactly as it does when a
     * payment clears one.
     *
     * Only ever open → paid. A return that covers part of a bill leaves it
     * open for the rest, which is correct, and one against an already-paid
     * bill leaves it paid — the money is out, and what comes back is the
     * supplier's problem to refund rather than a status change here.
     */
    private function resettleBills(PurchaseReturn $return): void
    {
        $billIds = $return->lines()
            ->whereNotNull('supplier_bill_id')
            ->pluck('supplier_bill_id')
            ->unique();

        foreach ($billIds as $billId) {
            $bill = SupplierBill::query()->find($billId);

            if ($bill?->status === SupplierBill::STATUS_OPEN && $bill->amountOutstanding() <= 0) {
                $bill->forceFill(['status' => SupplierBill::STATUS_PAID])->save();
            }
        }
    }

    /**
     * The goods have to still be there.
     *
     * A receipt line says what arrived, not what is left — it may have been
     * sold, transferred to another branch, or counted short. Sending back
     * stock we no longer hold would drive the level negative and put
     * Persediaan below what the valuation says it is.
     *
     * Checked against what is *available* rather than what is on hand, so a
     * return cannot quietly consume stock already fenced off for a customer's
     * confirmed order. That order was promised; this delivery going back was
     * not.
     */
    private function assertOnTheShelf(PurchaseReturn $return, string $sku, int $qty): void
    {
        $level = StockLevel::query()
            ->where('sku', $sku)
            ->where('warehouse_id', $return->warehouse_id)
            ->lockForUpdate()
            ->first();

        $available = $level?->qtyAvailable() ?? 0;

        if ($qty > $available) {
            throw new DomainException(sprintf(
                'Stok %s tidak cukup untuk diretur: diminta %d, tersedia %d di gudang ini.',
                $sku,
                $qty,
                $available,
            ));
        }
    }
}
