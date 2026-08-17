<?php

declare(strict_types=1);

namespace App\Domain\Stock;

use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\StockLevel;
use App\Models\StockOpname;
use App\Models\StockOpnameLine;
use App\Models\User;
use App\Models\Warehouse;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Drawing up a count sheet.
 *
 * The sheet snapshots what the system believes is on each shelf. That number
 * is on the paper for a reason worth being explicit about: it is what makes
 * the count a *check* rather than a data entry exercise. A blind count — no
 * system figure shown — is the stricter method and catches more, but it needs
 * a second pass to investigate every difference, and a small warehouse that
 * cannot spare two people will simply stop doing it.
 *
 * Whichever way it is counted, the snapshot is also how posting can tell that
 * stock moved between drawing the sheet and approving it — see
 * StockOpnamePoster, which refuses rather than adjusting to a stale count.
 */
class StockOpnameSheet
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * A sheet for every SKU the warehouse has ever held.
     *
     * Including the ones the system says are at zero. A shelf the system
     * thinks is empty is exactly where unrecorded stock accumulates, and a
     * count sheet that omits it can never find any.
     *
     * @param  list<string>|null  $skus  restrict to these, or null for everything
     */
    public function draw(
        Warehouse $warehouse,
        User $actor,
        ?array $skus = null,
        ?DateTimeInterface $tanggal = null,
        ?string $catatan = null,
    ): StockOpname {
        if (! $actor->role()->canCountStock()) {
            throw new DomainException('Anda tidak berhak membuat stok opname.');
        }

        $tanggal ??= now();

        return DB::transaction(function () use ($warehouse, $actor, $skus, $tanggal, $catatan) {
            $levels = StockLevel::query()
                ->where('warehouse_id', $warehouse->id)
                ->when($skus !== null, fn ($q) => $q->whereIn('sku', $skus))
                ->orderBy('sku')
                ->get();

            if ($levels->isEmpty()) {
                throw new DomainException(
                    "Gudang {$warehouse->nama} belum pernah menyimpan barang apa pun."
                );
            }

            $opname = StockOpname::create([
                'nomor' => $this->numbers->nextStockOpnameNumber($tanggal),
                'warehouse_id' => $warehouse->id,
                'tanggal' => $tanggal,
                'catatan' => $catatan,
                'created_by' => $actor->id,
            ]);

            foreach ($levels->values() as $i => $level) {
                StockOpnameLine::create([
                    'stock_opname_id' => $opname->id,
                    'sku' => $level->sku,
                    'urutan' => $i + 1,
                    'qty_system' => (int) $level->qty_on_hand,
                    // Left null. A zero would be indistinguishable from
                    // "counted, and the shelf was empty".
                    'qty_counted' => null,
                ]);
            }

            return $opname->refresh();
        });
    }

    /**
     * Record what was found, on the lines that were counted.
     *
     * @param  array<string, int>  $counts  sku => quantity found
     */
    public function record(StockOpname $opname, array $counts, User $actor): StockOpname
    {
        if (! $actor->role()->canCountStock()) {
            throw new DomainException('Anda tidak berhak mengisi hasil hitungan.');
        }

        if ($opname->isPosted()) {
            throw new DomainException("Opname {$opname->nomor} sudah diposting.");
        }

        return DB::transaction(function () use ($opname, $counts, $actor) {
            foreach ($counts as $sku => $qty) {
                if ($qty < 0) {
                    throw new DomainException("Hitungan {$sku} tidak boleh negatif.");
                }

                $line = $opname->lines()->where('sku', $sku)->first();

                if ($line === null) {
                    throw new DomainException("{$sku} tidak ada di lembar opname ini.");
                }

                $line->forceFill(['qty_counted' => (int) $qty])->save();
            }

            $opname->forceFill(['counted_by' => $actor->id])->save();

            return $opname->refresh();
        });
    }
}
