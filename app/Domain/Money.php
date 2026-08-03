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
