<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLogger;
use App\Domain\Integrity\IntegrityFinding;
use App\Domain\Integrity\LedgerIntegrity;
use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodReopening;
use App\Models\JournalEntry;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closing a month, and opening it again.
 *
 * Two rules do most of the work here, and both are about order rather than
 * about accounting:
 *
 *   - **Months close oldest first.** Closing August while July is still open
 *     would be theatre: anybody could post into July, and every year-to-date
 *     figure that included August would move.
 *   - **Months reopen newest first.** The mirror. Reopening July while August
 *     is closed leaves a hole in the middle of the locked range, and the next
 *     August close would have nothing to check against.
 *
 * Closing December also closes the year: one entry zeroes every income and
 * expense account into Laba Ditahan, so January starts from nil and the
 * balance sheet stops having to compute a result that nobody has posted.
 */
class PeriodCloser
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly FiscalCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Close a month.
     *
     * @param  int  $tahun  four digits
     * @param  int  $bulan  1–12
     * @param  string|null  $alasanTerpaksa  the Owner's reason for closing over
     *                                       a finding; anything else refuses
     */
    public function close(
        int $tahun,
        int $bulan,
        User $actor,
        ?string $catatan = null,
        ?string $alasanTerpaksa = null,
    ): AccountingPeriod {
        if (! $actor->role()->canClosePeriod()) {
            throw new DomainException('Anda tidak berhak menutup periode.');
        }

        $start = $this->startOf($tahun, $bulan);

        return DB::transaction(function () use ($tahun, $bulan, $actor, $catatan, $start, $alasanTerpaksa) {
            /*
             * Lock the whole table for the duration. Two people closing two
             * different months at the same instant could each see the other's
             * month as still open and both pass the ordering check, leaving a
             * gap that neither of them made. The table has one row per closed
             * month and closing happens monthly, so the contention costs
             * nothing.
             */
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);

            if ($this->calendar->isClosed($start)) {
                throw new LogicException("Periode {$start->translatedFormat('F Y')} sudah ditutup.");
            }

            $this->assertPeriodIsOver($start);
            $this->assertEverythingEarlierIsClosed($start);

            /*
             * Last of the guards, and deliberately so. The three above are
             * cheap and more fundamental — a month that has not ended yet, or
             * that has an open month before it, cannot be closed whatever the
             * books say, and telling somebody about a control account when
             * their real problem is that it is still August would bury the
             * answer they need. This one walks every SKU in every region.
             */
            $temuan = $this->assertBooksAddUp($actor, $alasanTerpaksa, $start);

            /*
             * The closing entry is posted *before* the period row exists.
             *
             * It is dated 31 December, inside the month being closed, so if
             * the row were written first the ledger would refuse the very
             * entry that closing the year is supposed to produce. Ordering it
             * this way means the guard needs no exception carved into it — and
             * a guard with no exceptions is one that cannot be reached around.
             */
            $closingEntry = $bulan === 12 ? $this->postClosingEntry($tahun, $actor) : null;

            $period = AccountingPeriod::create([
                'tahun' => $tahun,
                'bulan' => $bulan,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'catatan' => $catatan,
                'closing_entry_id' => $closingEntry?->id,
            ]);

            $this->audit->log(
                action: 'accounting_period_closed',
                subject: $period,
                newValue: [
                    'periode' => $start->format('Y-m'),
                    'closing_entry_id' => $closingEntry?->id,
                    'laba_ditutup_rupiah' => $closingEntry?->amount(),
                ],
                actor: $actor,
                alasan: $catatan,
            );

            /*
             * A close made over a known drift is its own event, not a footnote
             * on the ordinary one. CLAUDE.md: log every override with actor,
             * old value, new value, timestamp — the "old value" here is the
             * state of the books that was overridden, written out in full so
             * the question "what exactly did they sign off" has an answer that
             * does not depend on re-running the check months later against
             * data that has moved.
             */
            if ($temuan !== []) {
                $this->audit->log(
                    action: 'accounting_period_closed_over_findings',
                    subject: $period,
                    oldValue: ['temuan' => array_map(fn ($f) => [
                        'pemeriksaan' => $f->pemeriksaan,
                        'wilayah' => $f->wilayah,
                        'subjek' => $f->subjek,
                        'temuan' => $f->temuan,
                    ], $temuan)],
                    newValue: ['periode' => $start->format('Y-m'), 'ditutup' => true],
                    actor: $actor,
                    alasan: $alasanTerpaksa,
                );
            }

            return $period;
        });
    }

    /**
     * Open a closed month again.
     *
     * Owner only. Reversing a year-end close undoes the closing entry rather
     * than deleting it, so the fact that the year was closed and then opened
     * stays on the face of the ledger.
     */
    public function reopen(int $tahun, int $bulan, User $actor, string $alasan): AccountingPeriodReopening
    {
        if (! $actor->role()->canReopenPeriod()) {
            throw new DomainException('Hanya pemilik yang berhak membuka kembali periode yang sudah ditutup.');
        }

        if (trim($alasan) === '') {
            throw new LogicException('Membuka kembali periode harus disertai alasan.');
        }

        $start = $this->startOf($tahun, $bulan);

        return DB::transaction(function () use ($tahun, $bulan, $actor, $alasan, $start) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);

            $period = AccountingPeriod::query()
                ->where('tahun', $tahun)
                ->where('bulan', $bulan)
                ->first();

            if ($period === null) {
                throw new LogicException("Periode {$start->translatedFormat('F Y')} tidak sedang ditutup.");
            }

            $this->assertNothingLaterIsClosed($start);

            $closingEntry = $period->closingEntry;

            /*
             * Delete the lock first, then reverse — the same ordering as
             * close(), for the same reason. The reversal is dated to the
             * period it undoes, so it only lands cleanly once that period is
             * open again.
             */
            $period->delete();

            $reversal = $closingEntry === null ? null : $this->ledger->reverse(
                $closingEntry,
                $actor,
                "Periode {$start->translatedFormat('F Y')} dibuka kembali: {$alasan}",
                $closingEntry->tanggal,
            );

            $reopening = AccountingPeriodReopening::create([
                'tahun' => $tahun,
                'bulan' => $bulan,
                'reopened_by' => $actor->id,
                'alasan' => $alasan,
                'reversal_entry_id' => $reversal?->id,
            ]);

            $this->audit->log(
                action: 'accounting_period_reopened',
                subject: $reopening,
                oldValue: [
                    'periode' => $start->format('Y-m'),
                    'closed_by' => $period->closed_by,
                    'closed_at' => $period->closed_at?->toIso8601String(),
                ],
                newValue: ['reversal_entry_id' => $reversal?->id],
                actor: $actor,
                alasan: $alasan,
            );

            return $reopening;
        });
    }

    /**
     * What closing December would post, without posting it.
     *
     * The screen shows this before anybody commits to it. Same computation as
     * the real thing, because it is the real thing — a preview built from a
     * second copy of the rule is a preview that can lie.
     */
    public function previewYearEnd(int $tahun): ?JournalDraft
    {
        return $this->buildClosingDraft($tahun);
    }

    /** Advisory lock id, arbitrary but stable. */
    private const LOCK = 87_1201;

    /**
     * Zero income and expense into Laba Ditahan.
     *
     * Every account is closed at its own balance, not netted first: a reader
     * of this entry should be able to see the year's sales and the year's cost
     * of sales on it, which is the difference between a closing entry that
     * explains itself and one that says "profit, 51,348,800".
     */
    private function postClosingEntry(int $tahun, User $actor): ?JournalEntry
    {
        $draft = $this->buildClosingDraft($tahun);

        if ($draft === null) {
            return null;
        }

        return $this->ledger->post($draft, $actor);
    }

    /**
     * The entry that closes a year, or null if there is nothing to close.
     *
     * Scoped to this calendar year only, which is correct precisely because
     * periods close in order: to reach December 2026 every month back to the
     * first entry must already be closed, so December 2025 closed 2025 and the
     * income and expense accounts hold 2026's movement and nothing else.
     *
     * Which means `TrialBalance::asOf($akhirTahun)` would give the same answer
     * today — mutation testing says so, and it is right. The year window is
     * kept anyway for two reasons: it states what a year-end close is *about*
     * rather than relying on a rule enforced three methods away, and if the
     * close-in-order rule were ever relaxed it stays correct where the
     * cumulative form would quietly fold an earlier year's result into this
     * one's. A correctness argument that depends on a distant invariant is one
     * that breaks silently when the invariant moves.
     */
    private function buildClosingDraft(int $tahun): ?JournalDraft
    {
        $akhirTahun = Carbon::create($tahun, 12, 31)->startOfDay();
        $tb = TrialBalance::forPeriod(Carbon::create($tahun, 1, 1)->startOfDay(), $akhirTahun);

        $draft = JournalDraft::system(
            JournalEntry::JENIS_TUTUP_BUKU,
            "Tutup buku tahun {$tahun}",
            $akhirTahun,
        );

        $laba = 0;

        foreach ([AccountType::Pendapatan, AccountType::Beban] as $tipe) {
            foreach ($tb->rowsOfType($tipe) as $row) {
                $saldo = $row->balance();

                if ($saldo === 0) {
                    continue;
                }

                /*
                 * Close each account against its own normal side. Income
                 * carries a credit balance, so it is closed with a debit;
                 * expense the other way round. debitSigned handles an account
                 * sitting contrary to its nature — a sales return large enough
                 * to make revenue negative — without a special case.
                 */
                if ($tipe === AccountType::Pendapatan) {
                    $draft->debitSigned($row->account->kode, $saldo);
                    $laba += $saldo;
                } else {
                    $draft->kreditSigned($row->account->kode, $saldo);
                    $laba -= $saldo;
                }
            }
        }

        if ($laba === 0 && $draft->isEmpty()) {
            // A year with no trading. Nothing to close, and an entry for nil
            // would be a row that says something happened.
            return null;
        }

        $draft->kreditSigned(
            AccountCode::LABA_DITAHAN,
            $laba,
            $laba < 0 ? "Rugi tahun {$tahun}" : "Laba tahun {$tahun}",
        );

        return $draft;
    }

    /**
     * Do the books add up? Asked before the month is frozen.
     *
     * A close is the moment figures stop being provisional. Closing over a
     * control account that has left its subledger locks in a number nobody can
     * explain — and unlocking it again is Owner-only, so the cheap moment to
     * notice is now rather than in March.
     *
     * **Refused by default, and the refusal names what is out.** There is a
     * way through, because a business can have a drift it has investigated and
     * decided to live with until somebody has time, and a guard with no door
     * gets worked around by closing nothing at all. But the door is narrower
     * than the ordinary one: Finance may close a month whose books agree; only
     * the Owner may close one whose books do not, and only by saying why. The
     * same two-tier shape as reopening a closed month, for the same reason —
     * whoever is under pressure to publish a figure should not be the one who
     * can wave the check aside alone.
     *
     * @return list<IntegrityFinding> what was overridden
     */
    private function assertBooksAddUp(User $actor, ?string $alasanTerpaksa, Carbon $start): array
    {
        $temuan = app(LedgerIntegrity::class)->blockingFindings();

        if ($temuan === []) {
            return [];
        }

        $daftar = collect($temuan)
            ->map(fn ($f) => "{$f->wilayah} · {$f->subjek}: {$f->temuan}")
            ->implode(' ');

        if (! $actor->role()->canReopenPeriod()) {
            throw new DomainException(sprintf(
                'Buku %s belum cocok, jadi belum bisa ditutup. %s '
                .'Jelaskan dulu selisihnya — atau minta pemilik menutupnya dengan alasan tertulis.',
                $start->translatedFormat('F Y'),
                $daftar,
            ));
        }

        if (trim((string) $alasanTerpaksa) === '') {
            throw new DomainException(sprintf(
                'Buku %s belum cocok. %s Sebagai pemilik Anda boleh tetap menutupnya, '
                .'tapi alasannya harus ditulis dan akan tercatat di log audit.',
                $start->translatedFormat('F Y'),
                $daftar,
            ));
        }

        return $temuan;
    }

    private function assertPeriodIsOver(Carbon $start): void
    {
        $end = $start->copy()->endOfMonth()->startOfDay();

        if ($end->greaterThanOrEqualTo(Carbon::now()->startOfDay())) {
            throw new LogicException(
                "Periode {$start->translatedFormat('F Y')} belum berakhir. "
                .'Menutup bulan yang sedang berjalan mengunci sisa bulan itu juga.'
            );
        }
    }

    private function assertEverythingEarlierIsClosed(Carbon $start): void
    {
        $from = $this->calendar->firstMonthWithEntries();

        if ($from === null || $from->greaterThanOrEqualTo($start)) {
            return;
        }

        $cursor = $from->copy();

        while ($cursor->lessThan($start)) {
            if ($this->calendar->isOpen($cursor)) {
                throw new LogicException(
                    "Tutup {$cursor->translatedFormat('F Y')} dulu. Periode ditutup berurutan dari "
                    .'yang paling lama, supaya tidak ada bulan terbuka di tengah rentang yang terkunci.'
                );
            }

            $cursor->addMonth();
        }
    }

    private function assertNothingLaterIsClosed(Carbon $start): void
    {
        $later = AccountingPeriod::query()
            ->where(fn ($q) => $q
                ->where('tahun', '>', $start->year)
                ->orWhere(fn ($q) => $q->where('tahun', $start->year)->where('bulan', '>', $start->month)))
            ->orderBy('tahun')
            ->orderBy('bulan')
            ->first();

        if ($later !== null) {
            throw new LogicException(
                "Buka {$later->label()} dulu. Periode dibuka berurutan dari yang paling baru."
            );
        }
    }

    private function startOf(int $tahun, int $bulan): Carbon
    {
        if ($bulan < 1 || $bulan > 12) {
            throw new LogicException("Bulan {$bulan} tidak ada.");
        }

        return Carbon::create($tahun, $bulan, 1)->startOfDay();
    }
}
