<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use Carbon\CarbonImmutable;

/** The data start date from Preferences: the books begin on it, and opening balances are dated on it. */
final class DataStart
{
    public static function date(): ?CarbonImmutable
    {
        $date = app(Preferensi::class)->get(PreferensiKey::DataStartDate);

        return $date ? CarbonImmutable::parse($date)->startOfDay() : null;
    }

    /** The date an opening balance starts at: the data start date, else today. */
    public static function openingDate(): string
    {
        return (self::date() ?? CarbonImmutable::today())->toDateString();
    }
}
