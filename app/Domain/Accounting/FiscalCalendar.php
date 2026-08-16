<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Which months are closed, and therefore which dates the ledger will accept.
 *
 * One question, asked from two places: the guard inside Ledger, and the screen
 * that shows staff what they are about to lock. Both have to answer it the
 * same way, which is the reason this is a class rather than a query written
 * twice.
 *
 * The fiscal year is the calendar year — the default for a PT or CV unless the
 * DJP has approved otherwise — so a period is a calendar month and a year ends
 * on 31 December.
 */
class FiscalCalendar
{
    public function isClosed(DateTimeInterface $tanggal): bool
    {
        $date = Carbon::parse($tanggal);

        return AccountingPeriod::query()
            ->where('tahun', $date->year)
            ->where('bulan', $date->month)
            ->exists();
    }

    public function isOpen(DateTimeInterface $tanggal): bool
    {
        return ! $this->isClosed($tanggal);
    }

    /**
     * Refuse a date that falls in a closed month.
     *
     * `$dokumen` is what the person on the other end was trying to do, so the
     * message can say "Faktur bertanggal…" rather than "Jurnal bertanggal…".
     */
    public function assertOpen(DateTimeInterface $tanggal, string $dokumen = ''): void
    {
        if ($this->isClosed($tanggal)) {
            throw new ClosedPeriodException($tanggal, $dokumen);
        }
    }

    public function periodFor(DateTimeInterface $tanggal): ?AccountingPeriod
    {
        $date = Carbon::parse($tanggal);

        return AccountingPeriod::query()
            ->where('tahun', $date->year)
            ->where('bulan', $date->month)
            ->first();
    }

    /** The last closed month, or null if nothing has ever been closed. */
    public function lastClosed(): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->first();
    }

    /**
     * The earliest date the books can still accept.
     *
     * Everything before it is locked. Null when nothing is closed at all.
     */
    public function openFrom(): ?Carbon
    {
        $last = $this->lastClosed();

        return $last?->end()->addDay()->startOfDay();
    }

    /**
     * The first month that has anything in it.
     *
     * Derived from the ledger rather than configured, because a start date
     * somebody types is a start date that can be wrong, and the only honest
     * answer to "when do the books begin" is "when the first entry is dated".
     * Null when the ledger is empty.
     */
    public function firstMonthWithEntries(): ?Carbon
    {
        $earliest = JournalEntry::query()->min('tanggal');

        return $earliest === null ? null : Carbon::parse($earliest)->startOfMonth();
    }

    /**
     * Every month from the first entry to the current one, oldest first.
     *
     * What the tutup buku screen lists. A gap month with no trading still
     * appears: it can be closed, and leaving it out would make the "close in
     * order" rule look arbitrary when it refused the month after it.
     *
     * @return list<Carbon> the first day of each month
     */
    public function months(?DateTimeInterface $sampai = null): array
    {
        $from = $this->firstMonthWithEntries();

        if ($from === null) {
            return [];
        }

        $to = Carbon::parse($sampai ?? Carbon::now())->startOfMonth();

        // A closed period beyond `now` should still be listed, or the screen
        // would hide the very row somebody needs in order to reopen it.
        $last = $this->lastClosed();

        if ($last !== null && $last->start()->greaterThan($to)) {
            $to = $last->start();
        }

        if ($from->greaterThan($to)) {
            return [];
        }

        $months = [];
        $cursor = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * The month that is next in line to be closed.
     *
     * Periods close oldest first, so this is the only one that may be closed
     * right now. Null when there is nothing closable — either the ledger is
     * empty, or everything up to the current month is already closed.
     */
    public function nextToClose(): ?Carbon
    {
        $from = $this->firstMonthWithEntries();

        if ($from === null) {
            return null;
        }

        $last = $this->lastClosed();
        $candidate = $last === null ? $from : $last->start()->addMonth();

        // A period is closable only once it is over. Closing the month you are
        // standing in locks out the rest of it.
        return $candidate->endOfMonth()->startOfDay()->lessThan(Carbon::now()->startOfDay())
            ? $candidate->copy()->startOfMonth()
            : null;
    }
}
