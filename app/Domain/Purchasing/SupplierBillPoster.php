<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Tax\TaxCalculator;
use App\Models\SupplierBill;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Posts a supplier bill: what we owe is fixed, and the input VAT is computed.
 *
 * The mirror of InvoiceIssuer. Every figure comes from the bill's own line
 * snapshots; nothing is re-derived afterwards, and nothing may edit the total
 * once it is set — the same control the customer invoice carries, for the same
 * reason. Whoever pays must not be able to move the amount owed.
 *
 * PPN here is **pajak masukan**: input VAT creditable against the output VAT on
 * our sales, so it is real money rather than a formality. Same DPP nilai lain
 * of 11/12 as the sell side under PMK 131/2024 — confirm with the accountant
 * before touching any of it.
 */
class SupplierBillPoster
{
    public function __construct(
        private readonly TaxCalculator $tax,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly DocumentPoster $poster,
    ) {}

    /**
     * Compute the tax on each line, total the bill, and mark it posted.
     *
     * Idempotent by status: a bill already posted is returned untouched rather
     * than being totalled a second time.
     */
    public function post(SupplierBill $bill, User $actor): SupplierBill
    {
        if (! $actor->role()->canRecordPurchases()) {
            throw new DomainException('Anda tidak berhak memposting tagihan pemasok.');
        }

        return DB::transaction(function () use ($bill, $actor) {
            $locked = SupplierBill::query()->lockForUpdate()->findOrFail($bill->id);

            if ($locked->posted_at !== null) {
                return $locked;
            }

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Tagihan {$locked->nomor} tidak punya baris.");
            }

            $subtotal = 0;
            $dpp = 0;
            $ppn = 0;

            foreach ($lines as $line) {
                if ($line->line_total_rupiah < 0) {
                    throw new DomainException('Baris tagihan tidak boleh bernilai negatif.');
                }

                // Per line, never only on the total.
                $breakdown = $this->tax->forLine((int) $line->line_total_rupiah);

                $line->forceFill([
                    'dpp_rupiah' => $breakdown->dpp,
                    'ppn_rupiah' => $breakdown->ppn,
                ])->save();

                $subtotal += $breakdown->hargaJual;
                $dpp += $breakdown->dpp;
                $ppn += $breakdown->ppn;
            }

            $locked->forceFill([
                'subtotal_rupiah' => $subtotal,
                'dpp_rupiah' => $dpp,
                'ppn_rupiah' => $ppn,
                'total_rupiah' => $subtotal + $ppn,
                'kode_transaksi' => $this->tax->kodeTransaksi(),
                'status' => SupplierBill::STATUS_OPEN,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            /*
             * Clears the accrual at what the goods were received at, and puts
             * whatever the supplier charged on top into the variance account
             * rather than back into stock value.
             */
            $this->poster->supplierBillPosted($locked, $actor);

            $this->audit->log(
                action: 'supplier_bill_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'supplier_id' => $locked->supplier_id,
                    'nomor_faktur_supplier' => $locked->nomor_faktur_supplier,
                    'subtotal_rupiah' => $subtotal,
                    'ppn_rupiah' => $ppn,
                    'total_rupiah' => $locked->total_rupiah,
                    'due_date' => $locked->due_date->toDateString(),
                ],
                actor: $actor,
            );

            return $bill->refresh();
        });
    }

    /** The next bill number: TP-202608-0001. */
    public function nextNumber(): string
    {
        return $this->numbers->nextSupplierBillNumber();
    }
}
