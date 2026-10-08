<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets;

use App\Domain\Audit\Auditor;
use App\Domain\Posting\Exceptions\DocumentLockedException;
use App\Domain\Posting\PostingService;
use App\Models\FixedAssets\AssetDepreciation;
use App\Models\FixedAssets\FixedAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts the monthly depreciation (A-03). Every depreciating asset in use gets
 * one row per month from the month it was put to use up to the month asked
 * for, each posted on the month's last day. Idempotent: a month already posted
 * is left alone, so the scheduled run and a run from the screen agree.
 */
final class DepreciationRun
{
    public function __construct(private readonly PostingService $postings) {}

    /** @return array{posted: int, amount: int, skipped: list<string>} */
    public function upTo(CarbonImmutable|string $until, ?int $userId = null): array
    {
        $until = CarbonImmutable::parse($until)->endOfMonth();
        $userId ??= auth()->id();
        $posted = 0;
        $amount = 0;
        $skipped = [];

        $assets = FixedAsset::query()->active()
            ->where('depreciation_method', '!=', DepreciationMethod::None->value)
            ->where('usage_date', '<=', $until->toDateString())
            ->orderBy('number')
            ->get();

        foreach ($assets as $asset) {
            try {
                foreach ($this->missingPeriods($asset, $until) as $period) {
                    $row = $this->postPeriod($asset, $period, $userId);
                    if ($row !== null) {
                        $posted++;
                        $amount += $row->amount;
                    }
                }
            } catch (DocumentLockedException|RuntimeException $e) {
                $skipped[] = "{$asset->number}: {$e->getMessage()}";
            }
        }

        if ($posted > 0) {
            Auditor::log('depreciation_run', null, $until->format('Y-m'), ['posted' => $posted, 'amount' => $amount, 'skipped' => $skipped], $until->toDateString());
        }

        return ['posted' => $posted, 'amount' => $amount, 'skipped' => $skipped];
    }

    /** @return list<CarbonImmutable> month ends not yet depreciated, in order */
    private function missingPeriods(FixedAsset $asset, CarbonImmutable $until): array
    {
        $done = $asset->depreciations()->pluck('period')->all();
        $periods = [];
        $month = CarbonImmutable::parse($asset->usage_date)->startOfMonth();
        while ($month->lte($until)) {
            if (! in_array($month->format('Ym'), $done, true)) {
                $periods[] = $month->endOfMonth();
            }
            $month = $month->addMonth();
        }

        return $periods;
    }

    private function postPeriod(FixedAsset $asset, CarbonImmutable $monthEnd, ?int $userId): ?AssetDepreciation
    {
        return DB::transaction(function () use ($asset, $monthEnd, $userId): ?AssetDepreciation {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $monthsDone = $asset->depreciations()->count();
            $amount = Depreciator::monthly(
                $asset->depreciation_method,
                $asset->depreciableRemaining(),
                $asset->bookValue(),
                max(0, $asset->useful_life_months - $monthsDone),
                $asset->useful_life_months,
            );
            if ($amount <= 0) {
                return null;
            }
            $row = AssetDepreciation::query()->create([
                'fixed_asset_id' => $asset->id,
                'period' => $monthEnd->format('Ym'),
                'trans_date' => $monthEnd->toDateString(),
                'amount' => $amount,
                'accumulated_after' => $asset->accumulatedDepreciation() + $amount,
                'book_value_after' => $asset->bookValue() - $amount,
                'method' => $asset->depreciation_method->value,
                'created_by' => $userId,
                'created_at' => now(),
            ]);
            $this->postings->post($row, $userId);

            return $row;
        });
    }
}
