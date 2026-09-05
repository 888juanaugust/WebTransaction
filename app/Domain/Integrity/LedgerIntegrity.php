<?php

declare(strict_types=1);

namespace App\Domain\Integrity;

use App\Domain\Accounting\ControlAccountCheck;
use App\Domain\Accounting\Ledger;
use App\Domain\Accounting\LedgerReconciliation;
use App\Domain\Money;
use App\Domain\Regions\RegionContext;
use App\Domain\Stock\InventoryValuation;
use App\Domain\Stock\StockLedger;
use App\Models\Region;
use App\Models\Warehouse;

/**
 * The ledgers, asked whether they still add up.
 *
 * Every check here already existed. `StockLedger::reconcile()` says in its own
 * docblock "run it as a scheduled audit"; `InventoryValuation::reconcile()`
 * and `LedgerReconciliation` are the same shape. **Nothing ran any of them.**
 * The stock reconciler was reachable from the test suite and nowhere else, the
 * control accounts only from a screen somebody had to think to open, and
 * `stock_levels.qty_reserved` had no witness at all. A guard that is never run
 * is a comment.
 *
 * So this class does one thing: ask all four questions, everywhere, and give
 * the answers in a shape a person can act on.
 *
 *   1. Cached on-hand against the movement ledger — CLAUDE.md invariant 1,
 *      which says current stock "must always be reconstructible by summing
 *      the ledger".
 *   2. Cached reservations against the reservations actually held. Over-
 *      reserved is stock nobody can sell and nobody can explain; under-
 *      reserved is selling the same carton twice.
 *   3. Moving-average value against the cost ledger.
 *   4. Every control account against its subledger, and the trial balance.
 *
 * **Region by region, never unpinned**, and that is not a detail. Average cost
 * is kept per region — `product_costs` is unique on (region_id, sku) — so
 * running the valuation check with the scope open compares one region's cost
 * row against the movements of *all* regions and reports drift on a SKU that
 * is perfectly healthy. Verified, not assumed: two regions holding the same
 * SKU produce two false findings that way and none when pinned. A check that
 * cries wolf is switched off within a fortnight, and then it is not there on
 * the morning it was right.
 */
class LedgerIntegrity
{
    public function __construct(
        private readonly RegionContext $regions,
        private readonly StockLedger $stock,
        private readonly InventoryValuation $valuation,
        private readonly LedgerReconciliation $books,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Everything that does not add up, across every region.
     *
     * @return list<IntegrityFinding>
     */
    public function findings(): array
    {
        $out = [];

        foreach ($this->regionsToSweep() as $region) {
            foreach ($this->forRegion($region) as $finding) {
                $out[] = $finding;
            }
        }

        return $out;
    }

    public function isClean(): bool
    {
        return $this->findings() === [];
    }

    /**
     * The checks whose findings make a set of closing figures wrong.
     *
     * A control account that has left its subledger, or a stock value that has
     * left the cost ledger, changes numbers that go on the neraca and the laba
     * rugi. Closing a month over one of those freezes a figure nobody can
     * explain, and only the Owner can reopen a closed month to fix it.
     *
     * A drifted quantity cache is a different kind of wrong. It makes the
     * *warehouse* wrong — what the system thinks is on the shelf, what it will
     * let you promise a customer — and it makes no journal entry untrue.
     * Blocking a month-end close on it would stop the accountant for a
     * warehouse problem they cannot fix, which is how a guard gets routinely
     * overridden and stops meaning anything.
     */
    private const BLOCKING = ['buku', 'nilai_persediaan'];

    /**
     * Findings that should stop a period being closed.
     *
     * @return list<IntegrityFinding>
     */
    public function blockingFindings(): array
    {
        return array_values(array_filter(
            $this->findings(),
            fn (IntegrityFinding $f) => in_array($f->pemeriksaan, self::BLOCKING, true),
        ));
    }

    /**
     * The four checks inside one region's books.
     *
     * @return list<IntegrityFinding>
     */
    public function forRegion(Region $region): array
    {
        return $this->regions->within($region, function () use ($region) {
            $nama = $region->kode;

            return [
                ...$this->stockFindings($nama),
                ...$this->reservationFindings($nama),
                ...$this->valuationFindings($nama),
                ...$this->bookFindings($nama),
            ];
        });
    }

    /** @return list<IntegrityFinding> */
    private function stockFindings(string $wilayah): array
    {
        return array_map(
            fn (array $d) => new IntegrityFinding(
                pemeriksaan: 'stok',
                wilayah: $wilayah,
                subjek: $d['sku'].' @ '.$this->warehouseName($d['warehouse_id']),
                temuan: sprintf(
                    'Kolom stok tercatat %d, jumlah kartu stok %d (selisih %+d).',
                    $d['cached'], $d['ledger'], $d['cached'] - $d['ledger'],
                ),
            ),
            $this->stock->reconcile(),
        );
    }

    /** @return list<IntegrityFinding> */
    private function reservationFindings(string $wilayah): array
    {
        return array_map(
            fn (array $d) => new IntegrityFinding(
                pemeriksaan: 'reservasi',
                wilayah: $wilayah,
                subjek: $d['sku'].' @ '.$this->warehouseName($d['warehouse_id']),
                temuan: sprintf(
                    'Stok dipesan tercatat %d, reservasi yang benar-benar dipegang %d (selisih %+d).',
                    $d['cached'], $d['held'], $d['cached'] - $d['held'],
                ),
            ),
            $this->stock->reconcileReservations(),
        );
    }

    /** @return list<IntegrityFinding> */
    private function valuationFindings(string $wilayah): array
    {
        return array_map(
            fn (array $d) => new IntegrityFinding(
                pemeriksaan: 'nilai_persediaan',
                wilayah: $wilayah,
                subjek: $d['sku'],
                temuan: sprintf(
                    'Nilai tercatat %s atas %d unit; kartu stok %s atas %d unit.',
                    Money::format($d['cached_value']), $d['cached_qty'],
                    Money::format($d['ledger_value']), $d['ledger_qty'],
                ),
            ),
            $this->valuation->reconcile(),
        );
    }

    /**
     * The books: control accounts against their subledgers, plus the trial
     * balance itself.
     *
     * @return list<IntegrityFinding>
     */
    private function bookFindings(string $wilayah): array
    {
        $out = array_map(
            fn (ControlAccountCheck $c) => new IntegrityFinding(
                pemeriksaan: 'buku',
                wilayah: $wilayah,
                subjek: $c->kode.' '.$c->nama,
                temuan: sprintf(
                    'Buku besar %s, subledger %s (selisih %s). %s',
                    Money::format($c->buku),
                    Money::format($c->subledger),
                    Money::format($c->selisih()),
                    $c->sumber,
                ),
            ),
            $this->books->discrepancies(),
        );

        /*
         * Debits not equalling credits is a different kind of wrong from a
         * control account drifting: the first says an entry was written
         * badly, the second that a subledger and its summary disagree. Both
         * belong here, named apart.
         */
        if (! $this->ledger->isBalanced()) {
            $out[] = new IntegrityFinding(
                pemeriksaan: 'buku',
                wilayah: $wilayah,
                subjek: 'Neraca saldo',
                temuan: 'Debit dan kredit tidak seimbang.',
            );
        }

        return array_values($out);
    }

    /**
     * Which books to look at.
     *
     * Inactive regions included on purpose: a region is deactivated, never
     * deleted, and its books stay part of the company's. A drift there is
     * still a drift, and it is likelier to go unnoticed precisely because
     * nobody is working in it.
     *
     * @return iterable<Region>
     */
    private function regionsToSweep(): iterable
    {
        return $this->regions->acrossAll(
            fn () => Region::query()->orderBy('id')->get(),
        );
    }

    /** @var array<int, string> */
    private array $gudang = [];

    private function warehouseName(int $warehouseId): string
    {
        if ($this->gudang === []) {
            $this->gudang = $this->regions->acrossAll(
                fn () => Warehouse::query()->pluck('kode', 'id')->all(),
            );
        }

        return $this->gudang[$warehouseId] ?? "gudang #{$warehouseId}";
    }
}
