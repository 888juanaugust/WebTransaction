<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payments\PaymentLedger;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentEntry;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Proposes which journal line each statement line is — and never decides.
 *
 * A statement line and a Bank journal line that agree on amount, direction,
 * and roughly the date are almost certainly the same money, and "almost" is
 * the entire design: a suggestion is applied only when it is the *only*
 * candidate, and even the automatic pass is just the same tick a person would
 * have made, done in bulk. Two identical transfers on the same day stay
 * unmatched until a human looks, because guessing between them corrupts the
 * one control account an outsider can audit.
 *
 * Money in that the books have never seen — the customer transfer nobody
 * recorded — goes through `recordPayment()`, which is a pre-filled call to
 * the same `PaymentLedger` door finance always uses, then ticks the Bank leg
 * the posting produced. The statement can propose; only the ledger records.
 */
class StatementMatcher
{
    /** A book entry within this many days of the statement line qualifies. */
    public const JENDELA_HARI = 7;

    public function __construct(
        private readonly BankReconciler $reconciler,
        private readonly PaymentLedger $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Candidate journal lines per unmatched statement line.
     *
     * Only unticked candidates are offered: a ticked line is already claimed,
     * whether by the matcher or by a person's own hand.
     *
     * @return array<int, Collection<int, JournalLine>> keyed by statement line id
     */
    public function suggestions(BankStatementImport $import): array
    {
        $available = $this->availableJournalLines($import);

        return $import->lines()
            ->where('status', BankStatementLine::STATUS_BELUM)
            ->get()
            ->mapWithKeys(fn (BankStatementLine $line) => [
                $line->id => $this->candidatesFor($line, $available),
            ])
            ->all();
    }

    /**
     * A person says: this statement line is that journal line.
     *
     * The tick itself goes through the reconciler, which enforces the role,
     * the draft status, and candidacy. What is verified here is only what the
     * reconciler cannot know: that the two lines actually describe the same
     * movement of the same amount.
     */
    public function confirm(BankStatementLine $line, JournalLine $journalLine, User $actor): void
    {
        $this->assertUnmatched($line);

        if (! $this->agrees($line, $journalLine)) {
            throw new DomainException(
                'Baris mutasi dan baris jurnal tidak sepakat soal nilai atau arah uangnya.'
            );
        }

        DB::transaction(function () use ($line, $journalLine, $actor) {
            $this->reconciler->tick($line->import->reconciliation, $journalLine, $actor);

            $line->forceFill([
                'journal_line_id' => $journalLine->id,
                'status' => BankStatementLine::STATUS_TERCOCOK,
                'matched_by' => $actor->id,
                'matched_at' => now(),
            ])->save();
        });
    }

    /**
     * Apply every suggestion that is the only one for its line.
     *
     * Lines are walked in statement order and each confirmation removes its
     * journal line from the pool, so two identical statement lines against two
     * identical book entries resolve one-to-one instead of both grabbing the
     * first. Returns how many were matched.
     */
    public function autoMatch(BankStatementImport $import, User $actor): int
    {
        $available = $this->availableJournalLines($import);
        $matched = 0;

        $lines = $import->lines()
            ->where('status', BankStatementLine::STATUS_BELUM)
            ->orderBy('urutan')
            ->get();

        foreach ($lines as $line) {
            $candidates = $this->candidatesFor($line, $available);

            if ($candidates->count() !== 1) {
                continue;
            }

            $winner = $candidates->first();

            $this->confirm($line, $winner, $actor);

            $available = $available->reject(fn (JournalLine $l) => $l->id === $winner->id);
            $matched++;
        }

        if ($matched > 0) {
            $this->audit->log(
                action: 'bank_statement_auto_matched',
                subject: $import,
                newValue: ['jumlah_tercocok' => $matched],
                actor: $actor,
            );
        }

        return $matched;
    }

    /**
     * This line is not this reconciliation's problem — noise, or an era that
     * was settled outside the books. It keeps its reason.
     */
    public function ignore(BankStatementLine $line, User $actor, ?string $alasan = null): void
    {
        $this->assertMayWork($line, $actor);
        $this->assertUnmatched($line);

        $line->forceFill([
            'status' => BankStatementLine::STATUS_DIABAIKAN,
            'keterangan' => $alasan ?: 'Diabaikan.',
        ])->save();
    }

    /** Take a match (or an ignore) back, while the draft still allows it. */
    public function reset(BankStatementLine $line, User $actor): void
    {
        $this->assertMayWork($line, $actor);

        if ($line->status === BankStatementLine::STATUS_TERCOCOK && $line->journal_line_id !== null) {
            if ($line->payment_entry_id !== null) {
                throw new DomainException(
                    'Baris ini mencatatkan pembayaran. Batalkan pembayarannya lewat '
                    .'pembalikan di buku pembayaran, bukan dengan melepas cocokannya.'
                );
            }

            $this->reconciler->untick(
                $line->import->reconciliation,
                $line->journalLine,
                $actor,
            );
        }

        $line->forceFill([
            'journal_line_id' => null,
            'status' => BankStatementLine::STATUS_BELUM,
            'keterangan' => null,
            'matched_by' => null,
            'matched_at' => null,
        ])->save();
    }

    /**
     * Money in on the statement that the books never recorded: record it.
     *
     * Everything is taken from the statement line — amount, date, the uraian
     * as the note — and posted through the payment ledger's one door. The Bank
     * leg the posting produces is ticked immediately: it is on the statement
     * by definition, that is where it came from.
     */
    public function recordPayment(
        BankStatementLine $line,
        User $actor,
        ?Invoice $invoice = null,
        ?Company $company = null,
    ): PaymentEntry {
        $this->assertMayWork($line, $actor);
        $this->assertUnmatched($line);

        if ($line->arah !== BankStatementLine::ARAH_MASUK) {
            throw new DomainException(
                'Hanya uang masuk yang bisa dicatat sebagai pembayaran pelanggan. '
                .'Uang keluar dicatat sebagai item rekening koran.'
            );
        }

        $company = $invoice?->company ?? $company;

        if ($company === null) {
            throw new DomainException('Pilih faktur yang dibayar, atau pelanggan yang membayar.');
        }

        return DB::transaction(function () use ($line, $actor, $invoice, $company) {
            $entry = $this->payments->recordManualPayment(
                company: $company,
                amountRupiah: (int) $line->amount_rupiah,
                actor: $actor,
                invoice: $invoice,
                catatan: mb_substr("Mutasi bank: {$line->uraian}", 0, 255),
                paidAt: $line->tanggal,
                // The statement being matched IS this rekening's — the money
                // provably arrived there, not on the default.
                rekening: $line->import->reconciliation->bankAccount,
            );

            $bankLine = $this->bankLegOf($entry, $line->import->reconciliation->glAccount());

            $this->reconciler->tick($line->import->reconciliation, $bankLine, $actor);

            $line->forceFill([
                'journal_line_id' => $bankLine->id,
                'payment_entry_id' => $entry->id,
                'status' => BankStatementLine::STATUS_TERCOCOK,
                'matched_by' => $actor->id,
                'matched_at' => now(),
            ])->save();

            return $entry;
        });
    }

    /**
     * The Bank leg of the journal entry a payment posting produced.
     *
     * Found through the journal's own source link rather than remembered by
     * the ledger, so this cannot drift from what was actually posted.
     */
    private function bankLegOf(PaymentEntry $entry, Account $bank): JournalLine
    {
        $journal = JournalEntry::query()
            ->where('source_type', PaymentEntry::class)
            ->where('source_id', (string) $entry->id)
            ->orderByDesc('id')
            ->firstOrFail();

        return $journal->lines()->where('account_id', $bank->id)->firstOrFail();
    }

    /** @return Collection<int, JournalLine> unticked Bank lines this reconciliation could claim */
    private function availableJournalLines(BankStatementImport $import): Collection
    {
        $reconciliation = $import->reconciliation;
        $ticked = $this->reconciler->tickedLineIds($reconciliation);

        return $this->reconciler->candidateLines($reconciliation)
            ->reject(fn (JournalLine $line) => isset($ticked[$line->id]));
    }

    /** @param  Collection<int, JournalLine>  $available */
    private function candidatesFor(BankStatementLine $line, Collection $available): Collection
    {
        if ($line->tanggal === null || $line->amount_rupiah === null) {
            return collect();
        }

        return $available
            ->filter(fn (JournalLine $journalLine) => $this->agrees($line, $journalLine))
            ->values();
    }

    /** Same amount, same direction, close enough in time to be the same money. */
    private function agrees(BankStatementLine $line, JournalLine $journalLine): bool
    {
        $amount = (int) $line->amount_rupiah;

        $matchesAmount = $line->arah === BankStatementLine::ARAH_MASUK
            ? (int) $journalLine->debit_rupiah === $amount
            : (int) $journalLine->kredit_rupiah === $amount;

        if (! $matchesAmount || $amount === 0) {
            return false;
        }

        $tanggalJurnal = $journalLine->entry?->tanggal;

        if ($tanggalJurnal === null || $line->tanggal === null) {
            return false;
        }

        return abs($tanggalJurnal->diffInDays($line->tanggal, false)) <= self::JENDELA_HARI;
    }

    private function assertUnmatched(BankStatementLine $line): void
    {
        if ($line->status !== BankStatementLine::STATUS_BELUM) {
            throw new DomainException('Baris mutasi ini sudah ditangani.');
        }
    }

    private function assertMayWork(BankStatementLine $line, User $actor): void
    {
        if (! $actor->role()->canReconcileBank()) {
            throw new DomainException('Anda tidak berhak melakukan rekonsiliasi bank.');
        }

        if (! $line->import->reconciliation->isDraft()) {
            throw new DomainException('Rekonsiliasinya sudah selesai dan tidak bisa diubah lagi.');
        }
    }
}
