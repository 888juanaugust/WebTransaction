<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A month's depreciation, across every asset that owes one.
 *
 * A job somebody runs at month end, which makes **running it twice** the
 * failure that costs money — and the one that is hardest to notice, because
 * both runs succeed and the books simply carry twice the charge.
 *
 * Two things stop it, and the cheap one is not enough on its own. The unique
 * index on `(fixed_asset_id, periode)` is the real guard: it makes a second
 * charge for August physically impossible rather than merely unlikely. The
 * `firstOrCreate` above it is what turns that into a quiet no-op instead of an
 * exception halfway through a hundred assets.
 *
 * The ledger's own idempotency does **not** cover this. Each depreciation row
 * is its own journal source, so two rows would be two entries; it is the row
 * that has to be prevented, not the posting.
 *
 * Not scheduled automatically, deliberately. Depreciation is part of closing a
 * month — it belongs next to the person doing the close, who can see the result
 * before the period is locked. A cron job posting into a month nobody has
 * looked at is how a wrong figure gets reported and then frozen.
 */
class DepreciationRunner
{
    public function __construct(
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Post depreciation for one month.
     *
     * @return DepreciationRun what it did, so the screen can say so
     */
    public function run(string $periode, User $actor): DepreciationRun
    {
        $this->assertMayRun($actor);
        $this->assertPeriodShape($periode);

        $month = Carbon::createFromFormat('Y-m', $periode)->startOfMonth();

        if ($month->greaterThan(Carbon::now()->startOfMonth())) {
            throw new DomainException('Bulan ini belum lewat.');
        }

        // Charged on the last day of the month it belongs to, not the day the
        // button was pressed. A charge for August dated in September lands in
        // the wrong laba rugi and the wrong tax period.
        $tanggal = $month->copy()->endOfMonth();

        $posted = 0;
        $total = 0;
        $skipped = 0;

        /*
         * Disposed assets are included on purpose: one sold in September still
         * owes August. The schedule decides — it refuses the month of disposal
         * and everything after it.
         */
        $assets = FixedAsset::query()
            ->whereDate('tanggal_perolehan', '<=', $tanggal)
            ->orderBy('id')
            ->get();

        foreach ($assets as $asset) {
            $amount = DB::transaction(function () use ($asset, $periode, $tanggal, $actor) {
                return $this->chargeOne($asset, $periode, $tanggal, $actor);
            });

            if ($amount > 0) {
                $posted++;
                $total += $amount;
            } else {
                $skipped++;
            }
        }

        $this->audit->log(
            action: 'depreciation_run',
            subject: null,
            newValue: [
                'periode' => $periode,
                'aktiva_disusutkan' => $posted,
                'total_rupiah' => $total,
            ],
            actor: $actor,
        );

        return new DepreciationRun($periode, $posted, $skipped, $total);
    }

    /** @return int what was charged, or 0 if nothing was owed */
    private function chargeOne(FixedAsset $asset, string $periode, Carbon $tanggal, User $actor): int
    {
        $locked = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);

        $already = (int) FixedAssetDepreciation::query()
            ->where('fixed_asset_id', $locked->id)
            ->sum('amount_rupiah');

        $amount = (new DepreciationSchedule($locked))->charge($periode, $already);

        if ($amount <= 0) {
            return 0;
        }

        /*
         * firstOrCreate against the unique index. If the month has already been
         * run, this returns the existing row and nothing is posted — which is
         * what makes pressing the button twice harmless rather than expensive.
         */
        $row = FixedAssetDepreciation::query()->firstOrCreate([
            'fixed_asset_id' => $locked->id,
            'periode' => $periode,
        ], [
            'tanggal' => $tanggal,
            'amount_rupiah' => $amount,
            'nilai_buku_setelah_rupiah' => (int) $locked->harga_perolehan_rupiah - $already - $amount,
            'created_by' => $actor->id,
        ]);

        if (! $row->wasRecentlyCreated) {
            return 0;
        }

        $this->poster->depreciationPosted($row->refresh(), $actor);

        return $amount;
    }

    /** Which months still have assets owing depreciation and no run yet. */
    public function outstandingPeriods(int $lookback = 12): array
    {
        $periods = [];
        $month = Carbon::now()->startOfMonth();

        for ($i = 0; $i < $lookback; $i++) {
            $periode = $month->format('Y-m');

            $owed = FixedAsset::query()
                ->whereDate('tanggal_perolehan', '<=', $month->copy()->endOfMonth())
                ->get()
                ->contains(fn (FixedAsset $a) => (new DepreciationSchedule($a))->isDepreciableIn($periode)
                    && ! $a->depreciations()->where('periode', $periode)->exists());

            if ($owed) {
                $periods[] = $periode;
            }

            $month->subMonth();
        }

        return array_reverse($periods);
    }

    private function assertPeriodShape(string $periode): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $periode) !== 1) {
            throw new DomainException("Periode harus berbentuk YYYY-MM, bukan '{$periode}'.");
        }
    }

    private function assertMayRun(User $actor): void
    {
        if (! $actor->role()->canPostJournals()) {
            throw new DomainException('Anda tidak berhak memposting penyusutan.');
        }
    }
}
