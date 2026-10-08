<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;

/**
 * The aging columns, from Preferences: "current", then one bucket per
 * interval up to the range, then everything older. With a 30-day interval
 * and a 90-day range: current, 1–30, 31–60, 61–90, > 90.
 */
final class AgingBuckets
{
    /** @return list<array{key: string, label: string, max: int|null}> max days in the bucket; null for the last */
    public static function all(): array
    {
        $prefs = app(Preferensi::class);
        $interval = max(1, (int) $prefs->get(PreferensiKey::AgingIntervalDays));
        $range = max($interval, (int) $prefs->get(PreferensiKey::AgingRangeDays));

        $buckets = [['key' => 'current', 'label' => __('Current'), 'max' => 0]];
        for ($from = 1; $from <= $range; $from += $interval) {
            $to = min($from + $interval - 1, $range);
            $buckets[] = ['key' => "{$from}_{$to}", 'label' => "{$from}–{$to}", 'max' => $to];
        }
        $buckets[] = ['key' => "over_{$range}", 'label' => "> {$range}", 'max' => null];

        return $buckets;
    }

    /** The bucket key for an age in days. */
    public static function keyFor(int $days, ?array $buckets = null): string
    {
        foreach ($buckets ?? self::all() as $bucket) {
            if ($bucket['max'] === null || $days <= $bucket['max']) {
                return $bucket['key'];
            }
        }

        return 'current';
    }

    /** The basis Preferences start aging from: the invoice date or the due date. */
    public static function defaultBasis(): string
    {
        return (string) app(Preferensi::class)->get(PreferensiKey::AgingBasis) ?: 'invoice_date';
    }
}
