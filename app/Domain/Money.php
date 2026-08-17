<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Money is BIGINT rupiah. Never float.
 *
 * Everything here is integer arithmetic: the intermediate products are exact,
 * and rounding happens once, explicitly, at the end. Doing the same sums in
 * floating point drifts by a rupiah or two on large orders, and those rupiah
 * end up on a faktur pajak.
 */
final class Money
{
    /**
     * amount * numerator / denominator, rounded half-up, in integer rupiah.
     *
     * Half-up rather than banker's rounding because that is what Indonesian
     * invoicing conventionally does, and what the accountant will check against.
     */
    public static function mulDiv(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        $signs = ($amount < 0 ? 1 : 0) + ($numerator < 0 ? 1 : 0) + ($denominator < 0 ? 1 : 0);

        // Round the magnitude, then reapply the sign, so that -2.5 rounds to
        // -3 rather than -2. Half-*up* means away from zero on both sides.
        $product = abs($amount) * abs($numerator);
        $divisor = abs($denominator);

        $rounded = intdiv($product * 2 + $divisor, $divisor * 2);

        return $signs % 2 === 1 ? -$rounded : $rounded;
    }

    /**
     * Split an amount across weights so the parts add back up to it exactly.
     *
     * `mulDiv` per row does not do this. Round three thirds of Rp 100 and you
     * get 33 + 33 + 33 = 99, and the missing rupiah has to live somewhere — in
     * a clearing account that never quite empties, or in a reconciliation
     * check that reports a one-rupiah drift forever. Both teach people to
     * ignore the check.
     *
     * So: floor every share, then hand the remainder out one rupiah at a time
     * to whoever was robbed most by the flooring — largest remainder. Ties go
     * to the earlier row, which makes the result depend only on the inputs:
     * sorting the same weights in a different order must not move a rupiah
     * somewhere else. PHP's sort has been stable since 8.0, so equal
     * remainders keep their original sequence without an explicit tie-break.
     *
     * Zero weights get zero. All-zero weights spread evenly, because a charge
     * still has to land somewhere and refusing here would strand it.
     *
     * @param  list<int>  $weights
     * @return list<int> one share per weight, in the same order
     */
    public static function allocate(int $amount, array $weights): array
    {
        $count = count($weights);

        if ($count === 0) {
            throw new InvalidArgumentException('Nothing to allocate across.');
        }

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('A negative weight is not a share of anything.');
            }
        }

        $total = array_sum($weights);

        if ($total === 0) {
            $weights = array_fill(0, $count, 1);
            $total = $count;
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $i => $weight) {
            // Floor on the magnitude so a negative amount — a credit note
            // against a freight bill — spreads the same way in reverse.
            $exact = abs($amount) * $weight;
            $share = intdiv($exact, $total);

            $shares[$i] = $share;
            $remainders[$i] = $exact - $share * $total;
            $allocated += $share;
        }

        $leftover = abs($amount) - $allocated;

        // Sort a copy of the indices, largest remainder first. The shares
        // themselves stay in the caller's order.
        $order = range(0, $count - 1);
        usort($order, fn (int $a, int $b) => $remainders[$b] <=> $remainders[$a]);

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i % $count]]++;
        }

        return $amount < 0
            ? array_map(fn (int $share) => -$share, $shares)
            : $shares;
    }

    /** Apply a basis-point discount. 250 bps = 2.5% off. */
    public static function applyDiscountBps(int $amount, int $discountBps): int
    {
        if ($discountBps < 0 || $discountBps > 10_000) {
            throw new InvalidArgumentException("Discount out of range: {$discountBps} bps.");
        }

        return $amount - self::mulDiv($amount, $discountBps, 10_000);
    }

    /**
     * Round a fractional unit price to whole rupiah.
     *
     * Unit prices may carry four decimal places where fractional rupiah is
     * unavoidable; line totals never do.
     */
    public static function roundToRupiah(int|float|string $amount): int
    {
        return (int) round((float) $amount, 0, PHP_ROUND_HALF_UP);
    }

    /** 1234567 → "Rp 1.234.567" */
    public static function format(int $rupiah): string
    {
        return 'Rp '.number_format($rupiah, 0, ',', '.');
    }
}
