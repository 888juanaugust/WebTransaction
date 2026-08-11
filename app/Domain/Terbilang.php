<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * A rupiah amount written out in words.
 *
 * Indonesian invoices carry the total in words as well as figures — "terbilang"
 * — and a faktur without it reads as unfinished to the person filing it. It is
 * also the line a customer checks when the figure looks wrong.
 *
 * Integer rupiah only, like every other money path here. There are no cents to
 * spell, and accepting a float would be the one place in the codebase that
 * quietly rounded.
 */
final class Terbilang
{
    /** 0–11 are irregular; everything above is built from these. */
    private const SATUAN = [
        'nol', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];

    /**
     * "Rp 1.250.000" becomes "satu juta dua ratus lima puluh ribu rupiah".
     */
    public static function rupiah(int $amount): string
    {
        if ($amount < 0) {
            return 'minus '.self::rupiah(-$amount);
        }

        return self::words($amount).' rupiah';
    }

    /** The number alone, without the currency. */
    public static function words(int $number): string
    {
        if ($number < 0) {
            throw new InvalidArgumentException('Terbilang::words() expects a non-negative number.');
        }

        return self::spell($number);
    }

    private static function spell(int $n): string
    {
        // 0–11: the irregular run, including "sebelas" rather than "satu belas".
        if ($n < 12) {
            return self::SATUAN[$n];
        }

        if ($n < 20) {
            return self::spell($n - 10).' belas';
        }

        if ($n < 100) {
            return self::spell(intdiv($n, 10)).' puluh'.self::tail($n % 10);
        }

        // "seratus", not "satu ratus" — and the same shape for ribu below.
        if ($n < 200) {
            return 'seratus'.self::tail($n % 100);
        }

        if ($n < 1_000) {
            return self::spell(intdiv($n, 100)).' ratus'.self::tail($n % 100);
        }

        if ($n < 2_000) {
            return 'seribu'.self::tail($n % 1_000);
        }

        if ($n < 1_000_000) {
            return self::spell(intdiv($n, 1_000)).' ribu'.self::tail($n % 1_000);
        }

        if ($n < 1_000_000_000) {
            return self::spell(intdiv($n, 1_000_000)).' juta'.self::tail($n % 1_000_000);
        }

        if ($n < 1_000_000_000_000) {
            return self::spell(intdiv($n, 1_000_000_000)).' miliar'.self::tail($n % 1_000_000_000);
        }

        return self::spell(intdiv($n, 1_000_000_000_000)).' triliun'.self::tail($n % 1_000_000_000_000);
    }

    /** The remainder, spoken only when there is one. */
    private static function tail(int $remainder): string
    {
        return $remainder === 0 ? '' : ' '.self::spell($remainder);
    }
}
