<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Domain\Company\DataStart;
use App\Domain\Posting\Exceptions\PeriodClosedException;
use App\Domain\Shared\Format;
use App\Models\GeneralLedger\AccountingPeriod;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Closed months refuse every posting dated inside them, and nothing posts
 * before the data start date in Preferences. Months close in order (the
 * month after the last closed one) and reopen in reverse, so the closed range
 * is always one contiguous stretch from the first close.
 */
final class PeriodLock
{
    public function isClosed(DateTimeInterface|string $date): bool
    {
        $date = Carbon::parse($date);

        return AccountingPeriod::query()
            ->where('year', $date->year)
            ->where('month', $date->month)
            ->where('status', AccountingPeriod::CLOSED)
            ->exists();
    }

    public function assertOpen(DateTimeInterface|string $date, string $what = 'This transaction'): void
    {
        $start = DataStart::date();
        if ($start !== null && Carbon::parse($date)->startOfDay()->lt($start)) {
            throw new PeriodClosedException(__(':what is dated before the data start date, :date; the books begin there.', ['what' => $what, 'date' => Format::date($start)]));
        }
        if ($this->isClosed($date)) {
            $label = Carbon::parse($date)->translatedFormat('F Y');
            throw new PeriodClosedException(__(':what is dated in :month, which is closed. Reopen the month first.', ['what' => $what, 'month' => $label]));
        }
    }

    /** The last closed month, if any. */
    public function lastClosed(): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('status', AccountingPeriod::CLOSED)
            ->orderByDesc('year')->orderByDesc('month')
            ->first();
    }

    /** The month the month-end process may close next. */
    public function nextToClose(): CarbonInterface
    {
        $last = $this->lastClosed();
        if ($last === null) {
            return Carbon::create(now()->year, now()->month, 1)->subMonth()->startOfMonth();
        }

        return Carbon::create($last->year, $last->month, 1)->addMonth()->startOfMonth();
    }

    public function close(int $year, int $month, ?int $userId = null, ?string $notes = null): AccountingPeriod
    {
        $last = $this->lastClosed();
        if ($last !== null) {
            $expected = Carbon::create($last->year, $last->month, 1)->addMonth();
            if ($expected->year !== $year || $expected->month !== $month) {
                throw new RuntimeException(__('Months close in order: the next month to close is :month.', ['month' => $expected->translatedFormat('F Y')]));
            }
        }
        if (Carbon::create($year, $month, 1)->endOfMonth()->isFuture()) {
            throw new RuntimeException(__('A month cannot be closed before it has ended.'));
        }

        return AccountingPeriod::query()->updateOrCreate(
            ['year' => $year, 'month' => $month],
            ['status' => AccountingPeriod::CLOSED, 'closed_at' => now(), 'closed_by' => $userId, 'notes' => $notes],
        );
    }

    public function reopen(int $year, int $month): AccountingPeriod
    {
        $last = $this->lastClosed();
        if ($last === null || $last->year !== $year || $last->month !== $month) {
            throw new RuntimeException(__('Only the last closed month can be reopened.'));
        }
        $last->update(['status' => AccountingPeriod::OPEN, 'closed_at' => null, 'closed_by' => null]);

        return $last;
    }
}
