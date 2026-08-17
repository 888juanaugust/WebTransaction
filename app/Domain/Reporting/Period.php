<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A date range, and the one place that decides what "last month" means.
 *
 * Every report takes one of these rather than a pair of loose dates. Four
 * screens each parsing their own strings is four chances to be off by a day at
 * one end, and a sales figure that is off by a day at the end of a month is
 * exactly the figure somebody will reconcile against the ledger and find
 * wrong.
 *
 * `to` is inclusive and always the very end of its day. A range written
 * 1 August to 31 August that quietly stops at midnight loses a day's trading,
 * and nothing about the report says so.
 */
final class Period
{
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $label,
    ) {}

    public static function between(Carbon|string $from, Carbon|string $to): self
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('A period cannot end before it starts.');
        }

        return new self($start, $end, $start->translatedFormat('j M Y').' – '.$end->translatedFormat('j M Y'));
    }

    /**
     * A snapshot rather than a range.
     *
     * Ageing and lapsed customers are both "how things stand today" reports,
     * not "what happened between two dates" reports. Passing the same date
     * twice to `between()` labelled them `18 Agt 2026 – 18 Agt 2026`, which
     * reads like a range somebody got wrong rather than a position on a day.
     * The dates underneath still span the whole day, so anything filtering on
     * the period behaves as it did.
     */
    public static function asOf(Carbon|string $date): self
    {
        $day = Carbon::parse($date);

        return new self(
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
            'Per '.$day->translatedFormat('j M Y'),
        );
    }

    /** A calendar month, from `Y-m`. */
    public static function month(string $ym): self
    {
        $start = Carbon::createFromFormat('Y-m', $ym)->startOfMonth();

        return new self(
            $start->copy()->startOfDay(),
            $start->copy()->endOfMonth()->endOfDay(),
            $start->translatedFormat('F Y'),
        );
    }

    /**
     * The month somebody means when they sit down to look at figures.
     *
     * Last month, not this one. A part-finished month invites comparing it
     * with whole ones and concluding sales have collapsed.
     */
    public static function lastMonth(): self
    {
        return self::month(Carbon::now()->subMonthNoOverflow()->format('Y-m'));
    }

    public static function yearToDate(): self
    {
        $now = Carbon::now();

        return new self(
            $now->copy()->startOfYear()->startOfDay(),
            $now->copy()->endOfDay(),
            'Tahun berjalan '.$now->year,
        );
    }

    /** `Y-m` keys for every month the period touches, in order. */
    public function months(): array
    {
        $months = [];
        $cursor = $this->from->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->to)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }
}
