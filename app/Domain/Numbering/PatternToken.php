<?php

declare(strict_types=1);

namespace App\Domain\Numbering;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

/** The components a number format is made of, as the Numbering screen lists them. */
enum PatternToken: string implements HasLabel
{
    case Year = 'year';
    case ShortYear = 'short_year';
    case Month = 'month';
    case RomanMonth = 'roman_month';
    case Day = 'day';
    case Counter = 'counter';
    case Text = 'text';

    public function getLabel(): string
    {
        return match ($this) {
            self::Year => __('Year (2026)'),
            self::ShortYear => __('Year, short (26)'),
            self::Month => __('Month (10)'),
            self::RomanMonth => __('Month, Roman (X)'),
            self::Day => __('Day (17)'),
            self::Counter => __('Counter'),
            self::Text => __('Separator text'),
        };
    }

    public function render(CarbonInterface $date, int $counter, int $digits, ?string $text = null): string
    {
        return match ($this) {
            self::Year => $date->format('Y'),
            self::ShortYear => $date->format('y'),
            self::Month => $date->format('m'),
            self::RomanMonth => self::ROMAN[$date->month],
            self::Day => $date->format('d'),
            self::Counter => str_pad((string) $counter, $digits, '0', STR_PAD_LEFT),
            self::Text => (string) $text,
        };
    }

    private const ROMAN = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
}
