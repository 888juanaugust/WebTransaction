<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Carbon\CarbonImmutable;

/** Month pickers and month columns: the twelve English names, keyed 1..12. */
final class Months
{
    /** @return array<int, string> */
    public static function options(): array
    {
        $options = [];
        for ($month = 1; $month <= 12; $month++) {
            $options[$month] = self::name($month);
        }

        return $options;
    }

    public static function name(int|string|null $month): string
    {
        if ($month === null || $month === '' || (int) $month < 1 || (int) $month > 12) {
            return '';
        }

        return CarbonImmutable::create(2000, (int) $month, 1)->translatedFormat('F');
    }
}
