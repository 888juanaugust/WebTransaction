<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets;

use App\Domain\Shared\Money;

/**
 * One month's depreciation, as a pure function of what is left to depreciate.
 * Working from the remaining amounts rather than the original schedule means
 * a revaluation, an added cost, a new method or a partial disposal is absorbed
 * from the next month on, and the last month always lands on the salvage value.
 */
final class Depreciator
{
    /**
     * @param  int  $depreciableRemaining  cost − salvage − accumulated, for the part of the asset still held
     * @param  int  $bookValue  cost − accumulated, same part
     * @param  int  $monthsRemaining  useful life months not yet depreciated
     * @param  int  $lifeMonths  the whole useful life
     */
    public static function monthly(DepreciationMethod $method, int $depreciableRemaining, int $bookValue, int $monthsRemaining, int $lifeMonths): int
    {
        if (! $method->depreciates() || $depreciableRemaining <= 0 || $monthsRemaining <= 0) {
            return 0;
        }
        if ($monthsRemaining === 1) {
            return $depreciableRemaining;
        }

        // Whole rupiah on integers only, rounded half up (no float on the way).
        $amount = match ($method) {
            DepreciationMethod::StraightLine => Money::mulDiv($depreciableRemaining, 1, $monthsRemaining),
            // double declining: twice the straight-line rate on the book value
            DepreciationMethod::DecliningBalance => Money::mulDiv($bookValue, 2, max(1, $lifeMonths)),
            // remaining digits: R/(R(R+1)/2) = 2/(R+1) of what is left
            DepreciationMethod::SumOfYears => Money::mulDiv($depreciableRemaining, 2, $monthsRemaining + 1),
            DepreciationMethod::None => 0,
        };

        return max(0, min($amount, $depreciableRemaining));
    }
}
