<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Domain\Shared\Money;
use App\Models\Company\TaxCode;

/**
 * Tax per line, half-up at every step, from the tax code's rate and its DPP
 * fraction. For the 12 % VAT with DPP 11/12 (PMK 131/2024) the burden stays
 * 11 % of the price: an exclusive line of 1.000.000 has DPP 916.667, VAT
 * 110.000 and a gross of 1.110.000.
 */
final class TaxCalculator
{
    /**
     * @param  int  $amount  the line amount after discount; the price before tax
     *                       when $inclusive is false, the price the buyer pays when true
     */
    public static function forLine(int $amount, ?TaxCode $code, bool $inclusive = false): TaxResult
    {
        if ($code === null || $code->rateBasisPoints() === 0) {
            return new TaxResult($amount, $amount, 0, $amount);
        }

        $rateBp = $code->rateBasisPoints();
        $num = (int) $code->dpp_numerator;
        $den = (int) $code->dpp_denominator;

        if ($inclusive) {
            // gross = base + base × rate × num / den  ⇒  base = gross × den × 10000 / (den × 10000 + rate × num)
            $base = Money::mulDiv($amount, $den * 10000, $den * 10000 + $rateBp * $num);
            $tax = $amount - $base;
            $dpp = Money::mulDiv($base, $num, $den);

            return new TaxResult($base, $dpp, $tax, $amount);
        }

        $dpp = Money::mulDiv($amount, $num, $den);
        $tax = Money::mulDiv($dpp, $rateBp, 10000);

        return new TaxResult($amount, $dpp, $tax, $amount + $tax);
    }
}
