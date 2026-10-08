<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Rupiah arithmetic on integers. Every amount in the system is a whole number
 * of rupiah (BIGINT); nothing here produces a float that reaches storage.
 */
final class Money
{
    /** amount × numerator ÷ denominator, rounded half-up away from zero, on integers only. */
    public static function mulDiv(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        // Two amounts multiplied can pass 64 bits (a billion rupiah times a billion), so the product is a BigDecimal.
        return BigDecimal::of($amount)->multipliedBy($numerator)->dividedBy($denominator, 0, RoundingMode::HalfUp)->toInt();
    }

    /** A percentage given with up to four decimals ("11.5", "12", "0.25") of an amount, half-up. */
    public static function percent(int $amount, string|int|float $percent): int
    {
        $bps = self::toBasisPoints($percent);

        return self::mulDiv($amount, $bps, 10000);
    }

    /** "12.5" → 1250 basis points. Accepts an int, a float or a decimal string; four decimals at most. */
    public static function toBasisPoints(string|int|float $percent): int
    {
        $text = is_string($percent) ? trim(str_replace(',', '.', $percent)) : (string) $percent;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,4})\d*)?$/', $text, $m)) {
            throw new InvalidArgumentException("Not a percentage: {$text}");
        }
        $fraction = str_pad($m[3] ?? '', 2, '0');
        $bps = (int) $m[2] * 100 + (int) substr($fraction, 0, 2);
        $tail = substr($fraction, 2, 2);
        if ($tail !== '' && (int) $tail >= 50) {
            $bps++;
        }

        return ($m[1] === '-' ? -1 : 1) * $bps;
    }

    /**
     * Split an amount over weights so the parts add back up to it exactly
     * (largest remainder); zero weights get zero. Deterministic on ties: the
     * earlier part wins.
     *
     * @param  array<int|string, int>  $weights
     * @return array<int|string, int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            throw new InvalidArgumentException('Weights must add up to more than zero.');
        }

        // On the magnitude, so a negative amount rounds the same way a positive one does; the product of an amount
        // and a weight can pass 64 bits, so it is a BigInteger.
        $sign = $amount < 0 ? -1 : 1;
        $magnitude = abs($amount);
        $parts = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $key => $weight) {
            $exact = BigInteger::of($magnitude)->multipliedBy($weight);
            $part = $exact->quotient($total)->toInt();
            $parts[$key] = $part;
            $remainders[$key] = $exact->remainder($total)->toInt();
            $allocated += $part;
        }

        $left = $magnitude - $allocated;
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: array_search($a, array_keys($weights), true) <=> array_search($b, array_keys($weights), true));
        for ($i = 0; $i < $left; $i++) {
            $parts[$order[$i % count($order)]]++;
        }

        return array_map(fn (int $part) => $sign * $part, $parts);
    }

    /** 18450000 → "18.450.000" (or "18,450,000" under the English convention in Preferences), no decimals. */
    public static function format(int $amount): string
    {
        return ($amount < 0 ? '-' : '').number_format(abs($amount), 0, Format::decimalSeparator(), Format::thousandsSeparator());
    }

    /** "Rp 18.450.000", under the base currency's symbol. */
    public static function rupiah(int $amount): string
    {
        return Format::money($amount);
    }

    /** "18.450.000", "Rp 18.450.000", "18450000", "18.450.000,00" → 18450000 (English: "18,450,000.00"); rounds half-up when decimals are present. */
    public static function parse(string|int|float|null $text): int
    {
        if ($text === null || $text === '') {
            return 0;
        }
        if (is_int($text)) {
            return $text;
        }
        if (is_float($text)) {
            return (int) round($text, 0, PHP_ROUND_HALF_UP);
        }

        $clean = preg_replace('/[^\d,.\-]/', '', $text) ?? '';
        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');
        // The convention in Preferences: Indonesian "." groups thousands and "," starts decimals; English the reverse.
        $group = preg_quote(Format::thousandsSeparator(), '/');
        $point = preg_quote(Format::decimalSeparator(), '/');
        if (preg_match('/^(\d{1,3}(?:'.$group.'\d{3})*|\d+)(?:'.$point.'(\d+))?$/', $clean, $m)) {
            $whole = (int) str_replace(Format::thousandsSeparator(), '', $m[1]);
            $decimals = $m[2] ?? '';
        } elseif (preg_match('/^(\d+)(?:\.(\d+))?$/', $clean, $m)) {
            $whole = (int) $m[1];
            $decimals = $m[2] ?? '';
        } else {
            throw new InvalidArgumentException("Not an amount: {$text}");
        }
        if ($decimals !== '' && (int) $decimals[0] >= 5) {
            $whole++;
        }

        return $negative ? -$whole : $whole;
    }

    /**
     * An amount typed with decimals, in minor units of a currency with that many decimals: "1.000,50" (English
     * "1,000.50") or a plain "1000.50" → 100050 at two decimals; more decimals than the currency has round half-up.
     */
    public static function parseMinor(string|int|float|null $text, int $decimals): int
    {
        if ($text === null || $text === '') {
            return 0;
        }
        if (is_int($text)) {
            return $text * (10 ** $decimals);
        }
        $clean = preg_replace('/[^\d,.\-]/', '', (string) $text) ?? '';
        $negative = str_starts_with($clean, '-');
        $clean = ltrim($clean, '-');
        $group = preg_quote(Format::thousandsSeparator(), '/');
        $point = preg_quote(Format::decimalSeparator(), '/');
        if (preg_match('/^(\d{1,3}(?:'.$group.'\d{3})*|\d+)(?:'.$point.'(\d+))?$/', $clean, $m)) {
            $whole = str_replace(Format::thousandsSeparator(), '', $m[1]);
            $fraction = $m[2] ?? '';
        } elseif (preg_match('/^(\d+)(?:\.(\d+))?$/', $clean, $m)) {
            $whole = $m[1];
            $fraction = $m[2] ?? '';
        } else {
            throw new InvalidArgumentException("Not an amount: {$text}");
        }
        $minor = BigDecimal::of($whole.($fraction !== '' ? '.'.$fraction : ''))->toScale($decimals, RoundingMode::HalfUp)->getUnscaledValue()->toInt();

        return $negative ? -$minor : $minor;
    }
}
