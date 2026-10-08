<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** The company's fiscal year, starting in the month Preferences names (January by default). */
final class FiscalYear
{
    public static function startMonth(): int
    {
        return max(1, min(12, (int) app(Preferensi::class)->get(PreferensiKey::FiscalYearStartMonth)));
    }

    /** The first day of the fiscal year a date falls in: with an April start, 2026-02-10 → 2025-04-01. */
    public static function startOf(DateTimeInterface|string|null $date = null): CarbonImmutable
    {
        $date = CarbonImmutable::parse($date ?? 'today')->startOfDay();
        $start = $date->setDate($date->year, self::startMonth(), 1);

        return $start->gt($date) ? $start->subYear() : $start;
    }

    /** The last day of the fiscal year a date falls in. */
    public static function endOf(DateTimeInterface|string|null $date = null): CarbonImmutable
    {
        return self::startOf($date)->addYear()->subDay();
    }
}
