<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The Art. 21 figures for employees, from config('pajak.pph21'): the TER
 * category and monthly rate, PTKP, occupational cost and the Art. 17
 * brackets. Pure: no database.
 */
final class Pph21
{
    /** @return array<string, mixed> */
    private static function rules(): array
    {
        return (array) config('pajak.pph21');
    }

    public static function category(string $ptkp): string
    {
        return self::rules()['ter_category'][$ptkp] ?? throw new InvalidArgumentException("Unknown PTKP status: {$ptkp}");
    }

    /** The monthly TER rate (percent) of a category for a month's gross. */
    public static function terRate(string $category, int $gross): string
    {
        foreach (self::rules()['ter'][$category] as [$upTo, $rate]) {
            if ($upTo === null || $gross <= $upTo) {
                return (string) $rate;
            }
        }

        throw new InvalidArgumentException("No TER row for {$category} at {$gross}");
    }

    /** A rate as the tax office writes it: "6.00" → "6", "2.50" → "2.5". */
    public static function rateText(string|int|float|null $rate): string
    {
        $text = (string) ($rate ?? '0');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** The tax a TER rate withholds on a gross, rounded down to the rupiah. */
    public static function terTax(int $gross, string $rate): int
    {
        return max(0, BigDecimal::of($gross)->multipliedBy($rate)->dividedBy(100, 0, RoundingMode::Down)->toInt());
    }

    /** PTKP a year for "TK/2", "K/3" and the like. */
    public static function ptkp(string $status): int
    {
        $p = self::rules()['ptkp'];
        [$marital, $dependants] = array_pad(explode('/', $status, 2), 2, '0');

        return $p['self'] + ($marital === 'K' ? $p['married'] : 0) + min((int) $dependants, $p['max_dependants']) * $p['dependant'];
    }

    /** Occupational cost: the percentage of gross (down to the rupiah), at most the monthly cap for each month worked. */
    public static function biayaJabatan(int $gross, int $months): int
    {
        $rule = self::rules()['biaya_jabatan'];
        $cost = BigDecimal::of(max(0, $gross))->multipliedBy($rule['percent'])->dividedBy(100, 0, RoundingMode::Down)->toInt();

        return min($cost, $rule['monthly_cap'] * max(1, $months));
    }

    /** Taxable income: net less PTKP, never below zero, rounded down to the thousand. */
    public static function pkp(int $net, int $ptkp): int
    {
        return intdiv(max(0, $net - $ptkp), 1000) * 1000;
    }

    /** The year's tax on taxable income, bracket by bracket. */
    public static function article17(int $pkp): int
    {
        $tax = 0;
        $from = 0;
        foreach (self::rules()['brackets'] as [$upTo, $rate]) {
            $slice = $upTo === null ? $pkp - $from : min($pkp, $upTo) - $from;
            if ($slice <= 0) {
                break;
            }
            $tax += Money::percent($slice, (string) $rate);
            $from = (int) $upTo;
        }

        return $tax;
    }
}
