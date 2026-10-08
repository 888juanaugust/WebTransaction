<?php

declare(strict_types=1);

namespace App\Domain\FixedAssets;

use Filament\Support\Contracts\HasLabel;

/** The depreciation methods the standard offers. */
enum DepreciationMethod: string implements HasLabel
{
    case None = 'none';
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';
    case SumOfYears = 'sum_of_years';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => __('Not depreciated'),
            self::StraightLine => __('Straight line'),
            self::DecliningBalance => __('Declining balance'),
            self::SumOfYears => "Sum of the years' digits",
        };
    }

    public function depreciates(): bool
    {
        return $this !== self::None;
    }
}
