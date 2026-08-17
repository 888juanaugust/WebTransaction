<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Money;
use App\Domain\Stock\MovementReason;
use App\Domain\Stock\StockLedger;
use App\Models\LandedCost;
use App\Models\LandedCostLine;
use App\Models\ProductCost;
use App\Models\StockMovement;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posting an allocation: the charge stops floating and lands on the goods.
 *
 * The whole difficulty of landed cost is in one question — what about the part
 * of the shipment that has already been sold?
 *
 * Restating those shipments is out. Cost is frozen at the movement, and a
 * freight invoice arriving in September must not change August's gross margin
 * after somebody has reported it. Loading the whole charge onto what remains
 * is also out: if nine tenths of a container has gone, the last tenth would
 * absorb ten times its share and every subsequent sale of it would show a loss
 * that never happened.
 *
 * So the charge splits. The share belonging to goods still on the shelf raises
 * their cost, through a real movement, and will be recovered through margin as
 * they sell. The share belonging to goods already gone is a cost of the period
 * in which we found out about it, and goes straight to HPP. Nothing historical
 * is rewritten and no rupiah is lost.
 *
 * How much is "still on the shelf" is decided per SKU, not per receipt line,
 * because the moving average is one figure for the company and cannot tell one
 * carton of YH-1001 from another.
 *
 * That last point is the awkward one, and it is worth being explicit about the
 * approximation. Under average costing there is no way to know whether the
 * cartons sold last week came from *this* container or from the one before it.
 * Two readings of the current balance are both wrong:
 *
 *  - "on hand now" alone. Received 100, sold 40, then another 500 arrived from
 *    a delivery this freight bill never touched. On hand is 560, so the whole
 *    charge goes onto stock — quietly moving cost onto somebody else's goods.
 *  - "on hand now, capped at what arrived" fixes nothing here: 560 capped at
 *    100 is still 100, and the charge still lands in full.
 *
 * So the measure is **what has gone out since this shipment landed**, capped
 * both ways: never more than arrived, and never more than is on the shelf
 * today. That is a first-in-first-out assumption laid over an average-cost
 * system — goods leaving are assumed to deplete the shipment that was already
 * here — which is the conventional reading and the conservative one, since it
 * sends more of the charge to cost of sales and capitalises less.
 *
 * Transfers are excluded from that count. Stock walked across the yard has not
 * gone anywhere as far as the company is concerned, and counting the outbound
 * leg would charge the shipment for its own internal paperwork.
 */
class LandedCostPoster
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function post(LandedCost $landedCost, User $actor): LandedCost
    {
        if (! $actor->role()->canAllocateLandedCost()) {
            throw new DomainException('Anda tidak berhak mengalokasikan biaya perolehan.');
        }

        return DB::transaction(function () use ($landedCost, $actor) {
            $locked = LandedCost::query()->lockForUpdate()->findOrFail($landedCost->id);

            if ($locked->isPosted()) {
                throw new DomainException("Alokasi {$locked->nomor} sudah diposting.");
            }

            $this->assertChargeStillStands($locked);

            $lines = $locked->lines()->with('goodsReceiptLine.goodsReceipt')->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Alokasi {$locked->nomor} tidak punya baris.");
            }

            $toInventory = 0;
            $toCogs = 0;

            // Sorted by SKU for the same deadlock reason as everywhere else.
            foreach ($this->bySku($lines) as $sku => $group) {
                [$inventoryShare, $cogsShare, $onHand] = $this->split($sku, $group);

                $this->recordUplift($locked, $sku, $group, $inventoryShare, $onHand, $actor);

                $toInventory += $inventoryShare;
                $toCogs += $cogsShare;
            }

            $locked->forceFill([
                'status' => LandedCost::STATUS_POSTED,
                'ke_persediaan_rupiah' => $toInventory,
                'ke_hpp_rupiah' => $toCogs,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            /*
             * Dr Persediaan / Dr HPP / Cr Biaya Belum Dialokasikan. Posted from
             * the figures the movements actually carried rather than
             * recomputed, so the books and the valuation cannot disagree by a
             * rounding step.
             */
            $this->poster->landedCostAllocated($locked->refresh(), $actor);

            $this->audit->log(
                action: 'landed_cost_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'supplier_bill_line_id' => $locked->supplier_bill_line_id,
                    'dasar' => $locked->dasar->value,
                    'amount_rupiah' => (int) $locked->amount_rupiah,
                    'ke_persediaan_rupiah' => $toInventory,
                    'ke_hpp_rupiah' => $toCogs,
                    'baris' => $lines->count(),
                ],
                actor: $actor,
                alasan: $locked->catatan,
            );

            return $landedCost->refresh();
        });
    }

    /**
     * Group the lines by SKU, in a deterministic order.
     *
     * The same SKU can appear on two receipts in one allocation — a container
     * split across two deliveries — and treating those separately would ask
     * "how much is on hand" twice and count the same shelf both times.
     *
     * @param  Collection<int, LandedCostLine>  $lines
     * @return array<string, Collection<int, LandedCostLine>>
     */
    private function bySku(Collection $lines): array
    {
        return $lines->groupBy('sku')->sortKeys()->all();
    }

    /**
     * How this SKU's share of the charge divides between shelf and sold.
     *
     * @param  Collection<int, LandedCostLine>  $group
     * @return array{0: int, 1: int, 2: int} to inventory, to cost of sales, on hand
     */
    private function split(string $sku, Collection $group): array
    {
        $amount = (int) $group->sum('amount_rupiah');
        $received = (int) $group->sum('qty_base');

        if ($amount === 0 || $received <= 0) {
            return [0, $amount, 0];
        }

        /*
         * Locked before anything is read, because the split depends on the
         * ledger not moving underneath it: a shipment going out between the
         * read and the write would make the figure a guess.
         */
        $this->lockSku($sku);

        /*
         * What this shipment still has on the shelf, under the FIFO reading
         * set out at the top of this class.
         *
         * There is deliberately no second cap against the current balance.
         * Every reason that takes stock out of the company is counted below,
         * so this can never exceed what is actually there — and a cap that can
         * never bind is a guard nobody can test, which is worse than none.
         * The floor that does matter is in InventoryValuation::addCost, which
         * refuses to put value on a SKU holding nothing.
         */
        $onHand = max(0, $received - $this->issuedSince($sku, $group));

        $toInventory = Money::mulDiv($amount, $onHand, $received);

        return [$toInventory, $amount - $toInventory, $onHand];
    }

    /**
     * Base units that have left the company since this shipment landed.
     *
     * "Since" is measured from the earliest of the receipts in this group, so
     * one SKU arriving on two deliveries is treated as one pool rather than
     * asked about twice.
     *
     * Transfers are excluded: the goods are still ours, and the outbound leg
     * is matched by an inbound one. Everything else that reduces stock counts
     * — a sale, an opname shortfall — because from this charge's point of view
     * those goods are gone either way.
     *
     * @param  Collection<int, LandedCostLine>  $group
     */
    private function issuedSince(string $sku, Collection $group): int
    {
        $landedAt = $group
            ->map(fn (LandedCostLine $line) => $line->goodsReceiptLine?->goodsReceipt?->posted_at)
            ->filter()
            ->min();

        if ($landedAt === null) {
            return 0;
        }

        $issued = (int) StockMovement::query()
            ->where('sku', $sku)
            ->where('qty_signed', '<', 0)
            ->where('reason', '!=', MovementReason::TransferKeluar->value)
            ->where('created_at', '>=', $landedAt)
            ->sum('qty_signed');

        return -$issued;
    }

    /**
     * Write the uplift into the stock ledger, one movement per warehouse.
     *
     * Value is company-wide but a movement row is not, so a SKU received into
     * two warehouses gets its share split between them — by the amount each
     * line carried, largest remainder, so the movements sum to the figure the
     * journal is about to post.
     *
     * @param  Collection<int, LandedCostLine>  $group
     */
    private function recordUplift(
        LandedCost $landedCost,
        string $sku,
        Collection $group,
        int $inventoryShare,
        int $onHand,
        User $actor,
    ): void {
        $byWarehouse = $group->groupBy('warehouse_id')->sortKeys();

        $shares = Money::allocate(
            $inventoryShare,
            $byWarehouse->map(fn (Collection $lines) => (int) $lines->sum('amount_rupiah'))->values()->all(),
        );

        $i = 0;

        foreach ($byWarehouse as $warehouseId => $lines) {
            $share = $shares[$i++];

            // Zero is normal: everything from this shipment has been sold, and
            // the whole charge is going to HPP instead. addCost would refuse
            // it, and rightly — but there is nothing wrong here to refuse.
            if ($share > 0) {
                $this->stock->addCost(
                    sku: $sku,
                    warehouseId: (int) $warehouseId,
                    valueRupiah: $share,
                    referenceType: LandedCost::class,
                    referenceId: (string) $landedCost->id,
                    actor: $actor,
                    catatan: $landedCost->nomor,
                );
            }

            // Allocate again down to the individual lines, so what the
            // document shows adds up to what the movement carried. mulDiv per
            // line would not: three lines splitting an odd number lose a
            // rupiah, and the sum of the printed shares would not equal the
            // total printed above them.
            $lines = $lines->values();

            $lineShares = Money::allocate(
                $share,
                $lines->map(fn (LandedCostLine $line) => (int) $line->amount_rupiah)->all(),
            );

            foreach ($lines as $j => $line) {
                $line->forceFill([
                    'qty_on_hand' => $onHand,
                    'ke_persediaan_rupiah' => $lineShares[$j],
                    'ke_hpp_rupiah' => (int) $line->amount_rupiah - $lineShares[$j],
                ])->save();
            }
        }
    }

    /**
     * Hold the SKU's cost row for the rest of the transaction.
     *
     * Taken before the depletion figure is read rather than when the uplift is
     * written, so a shipment cannot slip in between deciding the split and
     * applying it. Nothing is read from the row — the lock is the point.
     */
    private function lockSku(string $sku): void
    {
        ProductCost::query()->where('sku', $sku)->lockForUpdate()->first();
    }

    /**
     * The charge has to still exist, and still be unspread.
     *
     * A draft can sit for days. In between, the bill it points at can be
     * voided — and posting against a voided bill would put value into stock
     * that nothing owes, driving the clearing account negative.
     */
    private function assertChargeStillStands(LandedCost $landedCost): void
    {
        $charge = $landedCost->supplierBillLine()->with('supplierBill')->first();

        if ($charge === null) {
            throw new DomainException('Baris biaya yang mau dialokasikan sudah tidak ada.');
        }

        $bill = $charge->supplierBill;

        if ($bill === null || $bill->posted_at === null || $bill->status === 'void') {
            throw new DomainException(
                'Tagihan biaya ini sudah dibatalkan atau belum diposting, jadi belum ada biaya untuk dibebankan.'
            );
        }

        if ((int) $charge->line_total_rupiah !== (int) $landedCost->amount_rupiah) {
            throw new DomainException(sprintf(
                'Nilai tagihan berubah sejak alokasi dibuat: lembar %s, tagihan %s. Buat alokasi baru.',
                Money::format((int) $landedCost->amount_rupiah),
                Money::format((int) $charge->line_total_rupiah),
            ));
        }
    }
}
