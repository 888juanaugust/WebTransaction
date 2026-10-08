<?php

declare(strict_types=1);

namespace App\Domain\Currency;

use App\Domain\Shared\Format;
use App\Domain\Shared\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Between a currency's minor units (cents) and the base currency's whole units, at a rate of base per one unit. */
final class Convert
{
    public static function toBase(int $minor, string|int|float $rate, int $decimals): int
    {
        return BigDecimal::of($minor)->multipliedBy((string) $rate)->dividedBy(BigDecimal::ten()->power($decimals), 0, RoundingMode::HalfUp)->toInt();
    }

    /** A typed amount ("1.000,50" under the Indonesian convention, "1,000.50" under the English one, or a plain "1000.50") in minor units. */
    public static function minor(string|int|float|null $major, int $decimals): int
    {
        return Money::parseMinor($major, $decimals);
    }

    public static function major(?int $minor, int $decimals): string
    {
        return (string) BigDecimal::ofUnscaledValue($minor ?? 0, $decimals);
    }

    /** Minor units as a form shows them for typing: the decimals after the convention's decimal separator, no grouping ("1000,50"). */
    public static function typed(?int $minor, int $decimals): string
    {
        return str_replace('.', Format::decimalSeparator(), self::major($minor, $decimals));
    }

    /** A unit price in the currency (major units) times the rate: the base unit price, to four places. */
    public static function priceToBase(string|int|float|null $price, string|int|float $rate): string
    {
        return (string) BigDecimal::of((string) ($price ?? 0))->multipliedBy((string) $rate)->toScale(4, RoundingMode::HalfUp);
    }

    /** A base price divided by the rate: the price in the currency, to four places. */
    public static function priceFromBase(string|int|float|null $price, string|int|float $rate): string
    {
        $rate = BigDecimal::of((string) $rate);

        return $rate->isZero() ? '0' : (string) BigDecimal::of((string) ($price ?? 0))->dividedBy($rate, 4, RoundingMode::HalfUp);
    }
}
