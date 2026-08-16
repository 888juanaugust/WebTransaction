<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The general ledger — the one place a journal entry can be written.
 *
 * Everything about this class is the same rule stated three ways:
 *
 *   - An entry balances or it is refused. Not warned about, not flagged for
 *     review. If debits do not equal credits the write does not happen, and
 *     the caller's transaction fails with it.
 *   - An entry is never edited. The only write that ever touches a posted row
 *     is stamping it reversed, and reversal is itself an entry.
 *   - Posting is idempotent per document. A queue job that runs twice, or a
 *     member of staff who clicks twice, gets one entry.
 *
 * Postings are made by explicit calls from inside each document's own
 * transaction, not from events. That is deliberate: the journal has to land
 * atomically with the thing it describes, and an event listener that fires
 * after commit can leave a shipment with no cost against it.
 */
class Ledger
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Post an entry caused by a document.
     *
     * No role check: this is a mechanical consequence of an action that was
     * already authorised. Whoever was allowed to ship the order was allowed to
     * cause the cost entry that shipping it produces.
     *
     * Returns the entry that already exists if this document has already
     * posted this jenis, without writing anything.
     */
    public function post(JournalDraft $draft, ?User $actor = null): JournalEntry
    {
        $this->assertPostable($draft);

        if ($draft->sourceType !== null) {
            $existing = $this->entryFor($draft->sourceType, $draft->sourceId, $draft->jenis);

            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return $this->write($draft, $actor);
        } catch (QueryException $e) {
            /*
             * Two callers raced and both found nothing. The unique index on
             * (source_type, source_id, jenis) settled it; the loser reads the
             * winner's row. This is the only reason that index exists, and it
             * is why idempotency is not left to the check above.
             */
            if ($draft->sourceType === null || ! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = $this->entryFor($draft->sourceType, $draft->sourceId, $draft->jenis);

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * Post an entry somebody wrote by hand.
     *
     * Nothing in the system caused this one, so the actor is mandatory and is
     * checked: an opening balance or a year-end accrual is a statement about
     * the business made on somebody's authority, and the books should say
     * whose.
     */
    public function postManual(JournalDraft $draft, User $actor): JournalEntry
    {
        if ($draft->jenis !== JournalEntry::JENIS_MANUAL) {
            throw new LogicException('postManual() takes a JournalDraft::manual() draft.');
        }

        if (! $actor->role()->canPostJournals()) {
            throw new DomainException('Anda tidak berhak memposting jurnal.');
        }

        $this->assertPostable($draft);

        $entry = $this->write($draft, $actor);

        $this->audit->log(
            action: 'journal_posted_manually',
            subject: $entry,
            newValue: [
                'nomor' => $entry->nomor,
                'tanggal' => $entry->tanggal->toDateString(),
                'keterangan' => $entry->keterangan,
                'total_rupiah' => $entry->amount(),
            ],
            actor: $actor,
        );

        return $entry;
    }

    /**
     * Undo an entry by posting its mirror image.
     *
     * The original stays exactly as it was, gains a pointer to its reversal,
     * and both remain on every report. That is the point: a figure that was
     * once stated and then withdrawn is part of the record, and a correction
     * that leaves no trace is indistinguishable from a cover-up.
     */
    public function reverse(
        JournalEntry $entry,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal = null,
    ): JournalEntry {
        if (! $actor->role()->canPostJournals()) {
            throw new DomainException('Anda tidak berhak membalik jurnal.');
        }

        if ($entry->isReversal()) {
            throw new LogicException('A reversal cannot itself be reversed.');
        }

        return DB::transaction(function () use ($entry, $actor, $alasan, $tanggal) {
            /*
             * Re-read under a lock. Two people reversing the same entry at the
             * same moment would otherwise both pass the check above and post
             * two mirrors, which nets to the original being removed twice.
             */
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($locked->reversed_by_entry_id !== null) {
                throw new LogicException("Jurnal {$locked->nomor} sudah dibalik.");
            }

            $reversal = $this->writeReversal($locked, $actor, $alasan, $tanggal);

            $locked->forceFill(['reversed_by_entry_id' => $reversal->id])->save();

            $this->audit->log(
                action: 'journal_reversed',
                subject: $reversal,
                oldValue: ['nomor' => $locked->nomor, 'total_rupiah' => $locked->amount()],
                newValue: ['nomor' => $reversal->nomor],
                actor: $actor,
                alasan: $alasan,
            );

            return $reversal;
        });
    }

    /** The entry a document posted for a given jenis, if it has. */
    public function entryFor(string $sourceType, ?string $sourceId, string $jenis): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('jenis', $jenis)
            ->first();
    }

    /** Every entry a document has posted, in date order. */
    public function entriesForDocument(Model $source): \Illuminate\Support\Collection
    {
        return JournalEntry::query()
            ->where('source_type', $source::class)
            ->where('source_id', (string) $source->getKey())
            ->orderBy('tanggal')
            ->orderBy('id')
            ->get();
    }

    /**
     * The balance of one account, in its own normal direction.
     *
     * Positive means the account holds what it is supposed to hold: cash on
     * the debit side, a payable on the credit side.
     */
    public function balanceOf(string $kode, ?DateTimeInterface $asOf = null): int
    {
        $account = Account::byCode($kode);

        $totals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('account_id', $account->id)
            ->when($asOf !== null, fn ($q) => $q->whereDate('journal_entries.tanggal', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(debit_rupiah), 0) AS d, COALESCE(SUM(kredit_rupiah), 0) AS k')
            ->first();

        return $account->saldo_normal->balance((int) $totals->d, (int) $totals->k);
    }

    /**
     * Does the whole ledger balance?
     *
     * It cannot fail unless something wrote to these tables without going
     * through this class, which is exactly what the check is for. Cheap enough
     * to run on a dashboard, and worth running there.
     */
    public function isBalanced(?DateTimeInterface $asOf = null): bool
    {
        $totals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($asOf !== null, fn ($q) => $q->whereDate('journal_entries.tanggal', '<=', $asOf))
            ->selectRaw('COALESCE(SUM(debit_rupiah), 0) AS d, COALESCE(SUM(kredit_rupiah), 0) AS k')
            ->first();

        return (int) $totals->d === (int) $totals->k;
    }

    /**
     * Everything that has to be true of a draft before any row is written.
     */
    private function assertPostable(JournalDraft $draft): void
    {
        /*
         * Two checks, not three. There is no separate "at least two lines"
         * rule because there cannot be a one-line entry that balances: every
         * line is added through debit() or kredit(), which zero the other
         * side, and a zero line is dropped. A single-line draft therefore
         * fails the balance check below — a guard above it would be a
         * protection that never fires, which reads as a protection that does.
         */
        if ($draft->isEmpty()) {
            throw new LogicException('A journal entry with no lines says nothing.');
        }

        if (! $draft->isBalanced()) {
            throw new UnbalancedJournalException($draft);
        }
    }

    private function write(JournalDraft $draft, ?User $actor): JournalEntry
    {
        return DB::transaction(function () use ($draft, $actor) {
            $accounts = $this->resolveAccounts($draft->accountCodes());

            $entry = JournalEntry::create([
                'nomor' => $this->numbers->nextJournalNumber($draft->tanggal),
                'tanggal' => $draft->tanggal,
                'keterangan' => $draft->keterangan,
                'source_type' => $draft->sourceType,
                'source_id' => $draft->sourceId,
                'jenis' => $draft->jenis,
                'total_debit_rupiah' => $draft->totalDebit(),
                'total_kredit_rupiah' => $draft->totalKredit(),
                'posted_by' => $actor?->id,
                'posted_at' => now(),
            ]);

            foreach ($draft->lines() as $urutan => $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $accounts[$line['kode']]->id,
                    'debit_rupiah' => $line['debit'],
                    'kredit_rupiah' => $line['kredit'],
                    'memo' => $line['memo'],
                    'company_id' => $line['company_id'],
                    'supplier_id' => $line['supplier_id'],
                    'urutan' => $urutan,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * The mirror. Built from the stored lines rather than from a fresh draft,
     * so a reversal is arithmetically the original and cannot drift from it.
     */
    private function writeReversal(
        JournalEntry $original,
        User $actor,
        string $alasan,
        ?DateTimeInterface $tanggal,
    ): JournalEntry {
        $tanggal = $tanggal !== null ? \Illuminate\Support\Carbon::parse($tanggal) : \Illuminate\Support\Carbon::now();

        $reversal = JournalEntry::create([
            'nomor' => $this->numbers->nextJournalNumber($tanggal),
            'tanggal' => $tanggal,
            'keterangan' => "Pembalikan {$original->nomor}: {$alasan}",
            'source_type' => $original->source_type,
            'source_id' => $original->source_id,
            'jenis' => JournalEntry::JENIS_PEMBALIKAN,
            'total_debit_rupiah' => $original->total_kredit_rupiah,
            'total_kredit_rupiah' => $original->total_debit_rupiah,
            'posted_by' => $actor->id,
            'posted_at' => now(),
            'reverses_entry_id' => $original->id,
        ]);

        foreach ($original->lines as $urutan => $line) {
            JournalLine::create([
                'journal_entry_id' => $reversal->id,
                'account_id' => $line->account_id,
                'debit_rupiah' => $line->kredit_rupiah,
                'kredit_rupiah' => $line->debit_rupiah,
                'memo' => $line->memo,
                'company_id' => $line->company_id,
                'supplier_id' => $line->supplier_id,
                'urutan' => $urutan,
            ]);
        }

        return $reversal->load('lines');
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, Account>
     */
    private function resolveAccounts(array $codes): array
    {
        $accounts = Account::query()->whereIn('kode', $codes)->get()->keyBy('kode');

        foreach ($codes as $kode) {
            $account = $accounts->get($kode);

            if ($account === null) {
                throw new LogicException("Akun {$kode} tidak ada di bagan akun.");
            }

            if (! $account->dapat_diposting) {
                throw new LogicException(
                    "Akun {$account->label()} adalah akun induk dan tidak bisa diposting."
                );
            }

            if (! $account->aktif) {
                throw new LogicException("Akun {$account->label()} sudah tidak aktif.");
            }
        }

        return $accounts->all();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 is Postgres' unique_violation; SQLSTATE 23000 is the generic form.
        return in_array($e->getCode(), ['23505', '23000'], true);
    }
}
