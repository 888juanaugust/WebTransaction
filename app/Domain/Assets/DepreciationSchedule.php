<?php

declare(strict_types=1);

namespace App\Domain\Assets;

use App\Models\FixedAsset;
use Illuminate\Support\Carbon;

/**
 * What one asset owes one month, and nothing else.
 *
 * Pure arithmetic, separated from the thing that posts it, because this is
 * where the mistakes live and they are all boundary mistakes.
 *
 * **Rounding.** A monthly charge is the depreciable base divided by the number
 * of months, and that rarely divides. Charging the rounded figure every month
 * leaves the asset a few hundred rupiah short of — or past — fully written off
 * after four years, and an asset carried at Rp 312 forever is the kind of
 * residue that outlives everybody who could explain it. So every month takes
 * the rounded-down figure and the **final month takes whatever is left**,
 * which makes accumulated depreciation land exactly on the base by
 * construction rather than by luck.
 *
 * **Where it starts.** UU PPh Pasal 11 ayat (3): depreciation begins in the
 * month the expenditure was made — a full month, not a pro-rated part of one.
 * An asset bought on the 29th of August takes a full August charge. That is
 * the rule the tax return is built on, so it is the rule here.
 *
 * **Where it stops.** The month the asset is disposed of takes no charge, and
 * nothing is ever charged past the depreciable base. The second guard matters
 * more than it looks: without it a monthly job left running for five years on
 * a four-year asset would keep crediting Akumulasi Penyusutan, and the neraca
 * would report negative fixed assets.
 */
final class DepreciationSchedule
{
    public function __construct(private readonly FixedAsset $asset) {}

    /**
     * The charge for one month, or nil if the asset owes nothing that month.
     *
     * `$periode` is 'YYYY-MM'. `$alreadyTaken` is what has been posted so far,
     * passed in rather than read here so the caller controls the transaction
     * it is read inside.
     */
    public function charge(string $periode, int $alreadyTaken): int
    {
        if (! $this->isDepreciableIn($periode)) {
            return 0;
        }

        $base = $this->asset->depreciableBase();
        $remaining = $base - $alreadyTaken;

        /*
         * Belt and braces. `isDepreciableIn()` above already stops once the
         * life has run out, so through the public API this is unreachable and
         * mutation testing correctly reports removing it as harmless. It stays
         * because the two guards protect different things: that one is about
         * *time*, this one is about *money*, and a life miscounted by a month
         * must still never write an asset below its residual.
         */
        if ($remaining <= 0) {
            return 0;
        }

        $months = max(1, (int) $this->asset->masa_manfaat_bulan);

        // intdiv, not round: the shortfall is deliberate, and the final month
        // below is what collects it.
        $monthly = intdiv($base, $months);

        /*
         * The final month takes everything left rather than another rounded
         * instalment. Forty-seven charges of 208,333 against a base of
         * 10,000,000 leave 208,349 — sixteen rupiah more than a normal month —
         * and charging the rounded figure again would strand that sixteen on
         * the books for the life of the company.
         *
         * The second case catches an asset that is nearly written off for some
         * other reason, so nothing can ever be charged past the base.
         */
        if ($periode === $this->lastPeriod()) {
            return $remaining;
        }

        /*
         * `min` for the same reason: with the sweep above, no ordinary month
         * can exceed what is left, so this cannot currently fire. It is the
         * one line that would keep a rounding change from ever overshooting
         * the base, which is the failure that puts a negative asset on the
         * neraca.
         *
         * `intdiv` rather than rounding is not arbitrary either, though the
         * *total* is identical either way because the final month sweeps: it
         * means no ordinary month is ever charged more than the even
         * instalment, so the monthly figure on the laba rugi is flat.
         */
        return min($monthly, $remaining);
    }

    /**
     * Does this asset take a charge in this month at all?
     *
     * Three ways it does not: it was bought later, it was disposed of earlier,
     * or its life has already run out.
     */
    public function isDepreciableIn(string $periode): bool
    {
        $month = Carbon::createFromFormat('Y-m', $periode)->startOfMonth();
        $acquired = Carbon::parse($this->asset->tanggal_perolehan)->startOfMonth();

        if ($month->lessThan($acquired)) {
            return false;
        }

        // The month of disposal takes no charge: the asset was not in service
        // for it, and the disposal entry already settles the book value.
        if ($this->asset->tanggal_pelepasan !== null) {
            $disposed = Carbon::parse($this->asset->tanggal_pelepasan)->startOfMonth();

            if ($month->greaterThanOrEqualTo($disposed)) {
                return false;
            }
        }

        return $month->lessThan($acquired->copy()->addMonths((int) $this->asset->masa_manfaat_bulan));
    }

    /** The month this asset stops being depreciated, for the register. */
    public function lastPeriod(): string
    {
        return Carbon::parse($this->asset->tanggal_perolehan)
            ->startOfMonth()
            ->addMonths((int) $this->asset->masa_manfaat_bulan - 1)
            ->format('Y-m');
    }
}
