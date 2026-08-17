<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Domain\Money;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\LandedCost;
use App\Models\LandedCostLine;
use App\Models\SupplierBillLine;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Drawing up an allocation: which goods a charge belongs to, and in what share.
 *
 * Like a count sheet, this is drawn rather than typed. The user picks the
 * charge and the receipts it covers; the lines and the arithmetic come from
 * what those receipts actually contain. Letting somebody type the shares by
 * hand would make the document a place to hide a number rather than a record
 * of a rule being applied.
 *
 * Nothing here touches stock or the ledger. It produces a draft that says what
 * *would* happen, which is the point — a freight allocation is a judgement
 * call about basis, and the person making it should see the split before
 * committing to it.
 */
class LandedCostAllocator
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /**
     * @param  Collection<int, GoodsReceipt>|list<GoodsReceipt>  $receipts
     */
    public function draw(
        SupplierBillLine $charge,
        iterable $receipts,
        AllocationBasis $basis,
        User $actor,
        ?string $catatan = null,
    ): LandedCost {
        if (! $actor->role()->canAllocateLandedCost()) {
            throw new DomainException('Anda tidak berhak mengalokasikan biaya perolehan.');
        }

        $charge->loadMissing('supplierBill');

        $this->assertChargeable($charge);

        $receipts = collect($receipts);

        if ($receipts->isEmpty()) {
            throw new DomainException('Pilih dulu penerimaan barang yang menanggung biaya ini.');
        }

        foreach ($receipts as $receipt) {
            if (! $receipt->isPosted()) {
                throw new DomainException(
                    "Penerimaan {$receipt->nomor} belum diposting, jadi belum ada barang yang bisa dibebani."
                );
            }
        }

        return DB::transaction(function () use ($charge, $receipts, $basis, $actor, $catatan) {
            /*
             * Re-checked under the transaction. Two people allocating the same
             * freight invoice at once would both pass the check above; the
             * unique index on supplier_bill_line_id decides, but a clear
             * refusal is better than an integrity violation in a log.
             */
            $this->assertNotAlreadyDrawn($charge);

            $lines = $this->receiptLines($receipts);

            if ($lines->isEmpty()) {
                throw new DomainException('Penerimaan yang dipilih tidak punya baris.');
            }

            $weights = $lines->map(fn (GoodsReceiptLine $line) => match ($basis) {
                AllocationBasis::Nilai => (int) $line->line_value_rupiah,
                AllocationBasis::Kuantitas => (int) $line->qty_base,
            })->all();

            $amount = (int) $charge->line_total_rupiah;

            // Largest remainder, so the shares add back to the charge exactly.
            // A rupiah lost here would sit in the clearing account forever.
            $shares = Money::allocate($amount, array_values($weights));

            $landedCost = LandedCost::create([
                'nomor' => $this->numbers->nextLandedCostNumber(),
                'supplier_bill_line_id' => $charge->id,
                'tanggal' => $charge->supplierBill?->tanggal_faktur ?? now()->toDateString(),
                'dasar' => $basis->value,
                'amount_rupiah' => $amount,
                'catatan' => $catatan,
                'created_by' => $actor->id,
            ]);

            foreach ($lines->values() as $i => $line) {
                LandedCostLine::create([
                    'landed_cost_id' => $landedCost->id,
                    'goods_receipt_line_id' => $line->id,
                    'sku' => $line->sku,
                    'urutan' => $i + 1,
                    'warehouse_id' => $line->goodsReceipt->warehouse_id,
                    'dasar_nilai' => $weights[$i],
                    'qty_base' => (int) $line->qty_base,
                    'amount_rupiah' => $shares[$i],
                ]);
            }

            return $landedCost->refresh();
        });
    }

    /**
     * Charges billed to us that nothing has spread yet.
     *
     * The worklist behind the allocation screen, and the same population the
     * clearing account's control check measures.
     *
     * @return Collection<int, SupplierBillLine>
     */
    public function unallocated(): Collection
    {
        return SupplierBillLine::query()
            ->with('supplierBill.supplier')
            ->where('supplier_bill_lines.jenis', SupplierBillLine::JENIS_BIAYA)
            ->whereHas('supplierBill', fn ($q) => $q
                ->whereNotNull('posted_at')
                ->where('status', '!=', 'void'))
            ->whereDoesntHave('landedCost')
            ->orderBy('supplier_bill_lines.id')
            ->get();
    }

    /**
     * Every line of the chosen receipts, in a stable order.
     *
     * Zero-quantity lines are dropped rather than given a share. On a value
     * basis they would take one anyway, and there is nothing on the shelf for
     * it to land on — the posting would push it all to cost of sales, which
     * reads as a freight write-off nobody asked for.
     *
     * @return Collection<int, GoodsReceiptLine>
     */
    private function receiptLines(Collection $receipts): Collection
    {
        return GoodsReceiptLine::query()
            ->with('goodsReceipt')
            ->whereIn('goods_receipt_id', $receipts->pluck('id')->all())
            ->where('qty_base', '>', 0)
            ->orderBy('goods_receipt_id')
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();
    }

    private function assertChargeable(SupplierBillLine $charge): void
    {
        if (! $charge->isBiaya()) {
            throw new DomainException(
                'Baris ini menagih barang, bukan biaya. Biaya perolehan hanya dari baris berjenis biaya.'
            );
        }

        $bill = $charge->supplierBill;

        if ($bill === null || $bill->posted_at === null) {
            throw new DomainException('Tagihan biaya ini belum diposting.');
        }

        if ($bill->status === 'void') {
            throw new DomainException("Tagihan {$bill->nomor} sudah dibatalkan.");
        }

        if ((int) $charge->line_total_rupiah <= 0) {
            throw new DomainException('Biaya yang dialokasikan harus lebih besar dari nol.');
        }

        $this->assertNotAlreadyDrawn($charge);
    }

    private function assertNotAlreadyDrawn(SupplierBillLine $charge): void
    {
        $existing = LandedCost::query()->where('supplier_bill_line_id', $charge->id)->first();

        if ($existing !== null) {
            throw new DomainException(
                "Biaya ini sudah dialokasikan lewat {$existing->nomor}."
                .($existing->isDraft() ? ' Hapus dulu draf itu kalau mau menghitung ulang.' : '')
            );
        }
    }
}
