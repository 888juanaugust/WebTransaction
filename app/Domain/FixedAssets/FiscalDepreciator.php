<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets;

use App\Domain\Company\FiscalYear;
use App\Models\FixedAssets\FiscalAssetCategory;
use App\Models\FixedAssets\FixedAsset;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Depreciation for the tax books, from the asset's fiscal group: its method,
 * useful life in years and yearly rate. Each fiscal year takes the rate on
 * the cost (straight line) or on the book value at the year's start
 * (declining balance), for the months the asset was in use that year; the
 * last year of the useful life takes whatever is left, so the cost is fully
 * depreciated. Nothing is posted: the tax books are a report.
 *
 * The method and rates follow the income tax law's groups; the accountant
 * confirms them (see docs/standard/_notes/README.md).
 */
final class FiscalDepreciator
{
    /** @return list<array{year_start: string, months: int, opening: int, depreciation: int, closing: int}> */
    public static function years(FixedAsset $asset): array
    {
        $group = $asset->fiscalCategory;
        if (! $asset->fiscal || $group === null || (int) $asset->cost <= 0) {
            return [];
        }
        $method = self::methodOf($group);
        $rate = BigDecimal::of((string) $group->rate_percent)->dividedBy(100, 8, RoundingMode::HalfUp);
        $lifeLeft = max(1, (int) $group->useful_life_years * 12);
        $cost = (int) $asset->cost;
        $book = $cost;
        $month = CarbonImmutable::parse($asset->usage_date)->startOfMonth();
        $rows = [];
        while ($lifeLeft > 0 && $book > 0) {
            $yearStart = FiscalYear::startOf($month);
            $monthsLeftInYear = (int) $month->diffInMonths($yearStart->addYear()) ?: 12;
            $months = min($monthsLeftInYear, $lifeLeft);
            $last = $months === $lifeLeft;
            $base = $method === DepreciationMethod::DecliningBalance ? $book : $cost;
            $amount = $last ? $book : min($book, BigDecimal::of($base)->multipliedBy($rate)->multipliedBy($months)->dividedBy(12, 0, RoundingMode::HalfUp)->toInt());
            $rows[] = ['year_start' => $yearStart->toDateString(), 'months' => $months, 'opening' => $book, 'depreciation' => $amount, 'closing' => $book - $amount];
            $book -= $amount;
            $lifeLeft -= $months;
            $month = $month->addMonths($months);
        }

        return $rows;
    }

    /** @return array<string, int> "Y-m" → that month's fiscal depreciation, each year spread evenly over its months */
    public static function months(FixedAsset $asset): array
    {
        $out = [];
        $month = CarbonImmutable::parse($asset->usage_date)->startOfMonth();
        foreach (self::years($asset) as $year) {
            $each = intdiv($year['depreciation'], $year['months']);
            for ($i = 0; $i < $year['months']; $i++) {
                $out[$month->format('Y-m')] = $i === $year['months'] - 1 ? $year['depreciation'] - $each * ($year['months'] - 1) : $each;
                $month = $month->addMonth();
            }
        }

        return $out;
    }

    public static function methodOf(FiscalAssetCategory $group): DepreciationMethod
    {
        $method = $group->depreciation_method;

        return $method instanceof DepreciationMethod ? $method : (DepreciationMethod::tryFrom((string) $method) ?? DepreciationMethod::StraightLine);
    }
}
