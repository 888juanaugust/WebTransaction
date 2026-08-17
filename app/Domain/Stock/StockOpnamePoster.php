<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Models\StockLevel;
use App\Models\StockOpname;
use App\Models\StockOpnameLine;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Approving a count, and writing the difference to the books.
 *
 * Two controls, and they are the whole reason this is a separate class from
 * the sheet:
 *
 * **The counter cannot approve their own count.** A stock count is the one
 * document whose purpose is to make missing goods disappear from the record.
 * Somebody who can both count a shelf and sign off what they found can walk
 * out with stock and file the paperwork themselves. So counting is Warehouse
 * and approving is Finance or Owner, and even an Owner who counted a sheet
 * cannot be the one to post it.
 *
 * **A stale count is refused, not applied.** If stock moved between drawing
 * the sheet and approving it, the variance on the paper is no longer the
 * variance in the system. Adjusting to it would push the shelf to a number
 * that was true an hour ago. Better to say so and count again.
 */
class StockOpnamePoster
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly InventoryValuation $valuation,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function post(StockOpname $opname, User $actor): StockOpname
    {
        if (! $actor->role()->canApproveStockCount()) {
            throw new DomainException(
                'Anda tidak berhak menyetujui hasil opname. Selisih stok disetujui oleh keuangan atau pemilik.'
            );
        }

        return DB::transaction(function () use ($opname, $actor) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);

            if ($locked->isPosted()) {
                throw new DomainException("Opname {$locked->nomor} sudah diposting.");
            }

            if ($locked->counted_by !== null && $locked->counted_by === $actor->id) {
                throw new DomainException(
                    'Yang menghitung tidak boleh menyetujui hitungannya sendiri.'
                );
            }

            $lines = $locked->lines()->whereNotNull('qty_counted')->get();

            if ($lines->isEmpty()) {
                throw new DomainException("Opname {$locked->nomor} belum ada yang dihitung.");
            }

            $this->assertNothingMovedSinceTheSheetWasDrawn($locked, $lines);

            $selisihQty = 0;
            $selisihNilai = 0;

            // Sorted by SKU for the same deadlock reason as everywhere else.
            foreach ($lines->sortBy('sku')->values() as $line) {
                $variance = (int) $line->qty_counted - (int) $line->qty_system;

                if ($variance === 0) {
                    $line->forceFill([
                        'selisih_qty' => 0,
                        'unit_cost_rupiah' => $this->valuation->unitCost($line->sku),
                        'selisih_rupiah' => 0,
                    ])->save();

                    continue;
                }

                /*
                 * A shortfall leaves at the running average, exactly as a
                 * shipment would; a surplus arrives at that same average,
                 * because there is no invoice saying what it cost — it is
                 * stock we apparently already owned and had not recorded.
                 */
                $movement = $this->stock->record(
                    sku: $line->sku,
                    warehouseId: $locked->warehouse_id,
                    qtySigned: $variance,
                    reason: MovementReason::Opname,
                    referenceType: StockOpname::class,
                    referenceId: (string) $locked->id,
                    actor: $actor,
                    catatan: $line->catatan,
                );

                $value = (int) ($movement->value_rupiah ?? 0);

                $line->forceFill([
                    'selisih_qty' => $variance,
                    'unit_cost_rupiah' => $movement->unit_cost_rupiah,
                    'selisih_rupiah' => $value,
                ])->save();

                $selisihQty += $variance;
                $selisihNilai += $value;
            }

            $locked->forceFill([
                'status' => StockOpname::STATUS_POSTED,
                'selisih_qty' => $selisihQty,
                'selisih_rupiah' => $selisihNilai,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ])->save();

            /*
             * Dr Selisih Persediaan / Cr Persediaan for a shortfall, and the
             * other way round for a surplus. Posted from the value the
             * movements actually carried rather than recomputed, so the books
             * and the valuation cannot disagree by a rounding step.
             */
            $this->poster->stockCountAdjusted($locked->refresh(), $actor);

            $this->audit->log(
                action: 'stock_opname_posted',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'warehouse_id' => $locked->warehouse_id,
                    'baris_dihitung' => $lines->count(),
                    'selisih_qty' => $selisihQty,
                    'selisih_rupiah' => $selisihNilai,
                    'counted_by' => $locked->counted_by,
                ],
                actor: $actor,
                alasan: $locked->catatan,
            );

            return $opname->refresh();
        });
    }

    /**
     * Refuse if the shelf changed under the count.
     *
     * The sheet records what the system said when it was drawn. If that no
     * longer matches, a shipment or a receipt landed in between, and the
     * counted figure is answering a question about a different moment.
     * Adjusting to it would set the shelf to a number that was true an hour
     * ago and silently swallow whatever moved since.
     *
     * @param  Collection<int, StockOpnameLine>  $lines
     */
    private function assertNothingMovedSinceTheSheetWasDrawn(StockOpname $opname, $lines): void
    {
        $current = StockLevel::query()
            ->where('warehouse_id', $opname->warehouse_id)
            ->whereIn('sku', $lines->pluck('sku')->all())
            ->pluck('qty_on_hand', 'sku');

        $moved = [];

        foreach ($lines as $line) {
            $now = (int) ($current[$line->sku] ?? 0);

            if ($now !== (int) $line->qty_system) {
                $moved[] = "{$line->sku} (lembar {$line->qty_system}, sekarang {$now})";
            }
        }

        if ($moved !== []) {
            throw new DomainException(
                'Stok berubah sejak lembar opname dibuat: '.implode(', ', $moved)
                .'. Buat lembar baru dan hitung ulang — menyesuaikan ke hitungan lama '
                .'akan menghapus perpindahan yang terjadi di antaranya.'
            );
        }
    }
}
