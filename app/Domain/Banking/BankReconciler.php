<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\JournalDraft;
use App\Domain\Accounting\Ledger;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\BankReconciliationLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Proving the Bank account against the only witness outside this system.
 *
 * Every other control account here is checked against a subledger we also
 * wrote: Piutang Usaha against our invoices, Persediaan against our costing.
 * Those prove the posting rules agree with each other. **None of them can
 * prove the money exists**, because both sides are our own arithmetic.
 *
 * This is the one that can. The statement balance is typed off a piece of
 * paper from the bank, and the whole exercise is to explain — line by line —
 * every difference between it and the books. What survives the explaining is
 * either a mistake or a transaction nobody recorded, and both are things
 * nothing else in this system will ever find.
 *
 * The working shape:
 *
 *   1. Open a reconciliation for a statement date and its closing balance.
 *   2. Tick every ledger line that appears on the statement.
 *   3. Whatever the statement has and the books do not, record as an item —
 *      that posts a real journal entry, because it is a real transaction the
 *      bank has already carried out.
 *   4. Finalise, which is refused while any difference remains.
 *
 * What is left unticked at the end is not a failure. A cheque written on the
 * 29th that the supplier banks in September is *supposed* to be outstanding;
 * it simply carries forward and gets ticked next month, without anybody
 * tracking it, because an unticked line is one with no row in the ticks table.
 */
class BankReconciler
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly DocumentNumberGenerator $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start a reconciliation for a statement.
     *
     * The date is unique across the table: two reconciliations to the same
     * closing date are two people doing the same job, and the second would
     * tick lines the first already claimed.
     */
    public function open(
        DateTimeInterface $statementDate,
        int $statementBalance,
        User $actor,
        ?string $catatan = null,
        ?BankAccount $rekening = null,
    ): BankReconciliation {
        $this->assertMayReconcile($actor);

        $rekening ??= app(BankAccounts::class)->default();
        $date = Carbon::parse($statementDate)->startOfDay();

        if ($date->isFuture()) {
            throw new DomainException('Belum ada rekening koran untuk tanggal yang belum lewat.');
        }

        /*
         * Entity-wide, like everything else about a reconciliation: one real
         * account, one statement, one reconciliation of it. Scoped, two
         * regions could each open one for the same account and the same date
         * without either seeing the other — two half-proofs of one account,
         * and neither would ever balance.
         */
        $existing = BankReconciliation::query()
            ->withoutGlobalScope('region')
            ->where('bank_account_id', $rekening->id)
            ->whereDate('tanggal_rekening', $date->toDateString())
            ->first();

        if ($existing !== null) {
            throw new DomainException(sprintf(
                'Rekonsiliasi untuk %s sudah ada (%s, %s).',
                $date->format('d/m/Y'),
                $existing->nomor,
                $existing->isFinalised() ? 'selesai' : 'masih draf',
            ));
        }

        /*
         * Nothing before the last finalised statement may be reconciled again.
         * Those lines are already ticked, so they would not appear anyway —
         * this catches the other half: opening a *January* reconciliation in
         * September, which would present three seasons of already-explained
         * history as though it were outstanding.
         */
        $last = $this->lastFinalised($rekening);

        if ($last !== null && $date->lessThanOrEqualTo($last->tanggal_rekening)) {
            throw new DomainException(sprintf(
                'Rekening koran %s sudah direkonsiliasi sampai %s.',
                $last->nomor,
                $last->tanggal_rekening->format('d/m/Y'),
            ));
        }

        $reconciliation = BankReconciliation::create([
            'nomor' => $this->numbers->nextBankReconciliationNumber($date),
            'tanggal_rekening' => $date,
            'saldo_rekening_rupiah' => $statementBalance,
            'catatan' => $catatan,
            'created_by' => $actor->id,
            'bank_account_id' => $rekening->id,
        ]);

        $this->audit->log(
            action: 'bank_reconciliation_opened',
            subject: $reconciliation,
            newValue: [
                'nomor' => $reconciliation->nomor,
                'tanggal_rekening' => $date->toDateString(),
                'saldo_rekening_rupiah' => $statementBalance,
                'rekening' => $rekening->label(),
            ],
            actor: $actor,
        );

        return $reconciliation->refresh();
    }

    /**
     * Every Bank line this reconciliation could still tick.
     *
     * Dated on or before the statement date — a line from next week cannot be
     * on this statement — and not already ticked by any other reconciliation.
     * A line ticked by *this* one is included, so the screen can show it
     * ticked rather than making it vanish.
     *
     * @return Collection<int, JournalLine>
     */
    public function candidateLines(BankReconciliation $reconciliation): Collection
    {
        $bank = $reconciliation->glAccount();

        return JournalLine::query()
            ->with(['entry'])
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            /*
             * Deliberately **not** `whereBoundRegion` — the one place in the
             * ledger reads where that is right. Everything on the statement is
             * a candidate, whichever region's books posted it, and the balance
             * this is subtracted from is entity-wide for the same reason. The
             * absence is load-bearing, so it is written down: a join does not
             * carry the region scope, and this query relies on that.
             */
            ->where('journal_lines.account_id', $bank->id)
            ->whereDate('journal_entries.tanggal', '<=', $reconciliation->tanggal_rekening)
            ->whereNotExists(fn ($q) => $q
                ->from('bank_reconciliation_lines')
                ->whereColumn('bank_reconciliation_lines.journal_line_id', 'journal_lines.id')
                ->where('bank_reconciliation_lines.bank_reconciliation_id', '!=', $reconciliation->id))
            ->orderBy('journal_entries.tanggal')
            ->orderBy('journal_lines.id')
            ->select('journal_lines.*')
            ->get();
    }

    /** @return array<int, true> keyed by journal line id */
    public function tickedLineIds(BankReconciliation $reconciliation): array
    {
        return $reconciliation->ticks()
            ->pluck('journal_line_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** This line appeared on the statement. */
    public function tick(BankReconciliation $reconciliation, JournalLine $line, User $actor): void
    {
        $this->assertMayReconcile($actor);
        $this->assertDraft($reconciliation);
        $this->assertIsCandidate($reconciliation, $line);

        BankReconciliationLine::query()->firstOrCreate([
            'journal_line_id' => $line->id,
        ], [
            'bank_reconciliation_id' => $reconciliation->id,
        ]);
    }

    /**
     * It did not, after all.
     *
     * Only ever removes a tick belonging to this reconciliation. Unticking a
     * line a *finalised* one claimed would silently reopen a month that has
     * already been signed off.
     */
    public function untick(BankReconciliation $reconciliation, JournalLine $line, User $actor): void
    {
        $this->assertMayReconcile($actor);
        $this->assertDraft($reconciliation);

        BankReconciliationLine::query()
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->where('journal_line_id', $line->id)
            ->delete();
    }

    /** Tick everything still untouched — the ordinary case for a quiet month. */
    public function tickAll(BankReconciliation $reconciliation, User $actor): int
    {
        $this->assertMayReconcile($actor);
        $this->assertDraft($reconciliation);

        $ticked = $this->tickedLineIds($reconciliation);
        $count = 0;

        foreach ($this->candidateLines($reconciliation) as $line) {
            if (isset($ticked[$line->id])) {
                continue;
            }

            $this->tick($reconciliation, $line, $actor);
            $count++;
        }

        return $count;
    }

    /**
     * Something on the statement the books had never heard of.
     *
     * Posts immediately rather than at finalisation, because it is not a
     * proposal — the bank has already taken the fee or paid the interest, and
     * the ledger being ignorant of it is the defect being corrected. Then it
     * ticks itself: it is on the statement by definition, and leaving it
     * unticked would move the difference by exactly the amount just explained.
     */
    public function recordStatementItem(
        BankReconciliation $reconciliation,
        string $keterangan,
        int $amountRupiah,
        StatementDirection $arah,
        string $accountCode,
        User $actor,
        ?DateTimeInterface $tanggal = null,
    ): BankReconciliationItem {
        $this->assertMayReconcile($actor);
        $this->assertDraft($reconciliation);

        if ($amountRupiah <= 0) {
            throw new DomainException('Nilai item rekening koran harus lebih dari nol.');
        }

        if (trim($keterangan) === '') {
            throw new DomainException('Item rekening koran harus punya keterangan.');
        }

        $account = Account::byCode($accountCode);
        $bankKode = $reconciliation->glAccount()->kode;

        if ($accountCode === $bankKode) {
            throw new DomainException(
                'Lawan jurnalnya tidak boleh rekening ini sendiri — itu akan membatalkan dirinya sendiri.'
            );
        }

        $date = Carbon::parse($tanggal ?? $reconciliation->tanggal_rekening);

        if ($date->greaterThan($reconciliation->tanggal_rekening)) {
            throw new DomainException(
                'Tanggal item tidak boleh setelah tanggal rekening koran.'
            );
        }

        return DB::transaction(function () use (
            $reconciliation, $keterangan, $amountRupiah, $arah, $account, $actor, $date
        ) {
            $item = BankReconciliationItem::create([
                'bank_reconciliation_id' => $reconciliation->id,
                'tanggal' => $date,
                'keterangan' => trim($keterangan),
                'account_id' => $account->id,
                'arah' => $arah,
                'amount_rupiah' => $amountRupiah,
                'created_by' => $actor->id,
            ]);

            $draft = JournalDraft::for(
                $item,
                JournalEntry::JENIS_REKONSILIASI_BANK,
                sprintf('%s — %s', $reconciliation->nomor, trim($keterangan)),
                $date,
            );

            [$debit, $kredit] = $arah->debitsBank()
                ? [$reconciliation->glAccount()->kode, $account->kode]
                : [$account->kode, $reconciliation->glAccount()->kode];

            $draft->debit($debit, $amountRupiah, trim($keterangan))
                ->kredit($kredit, $amountRupiah, trim($keterangan));

            $entry = $this->ledger->post($draft, $actor);

            $item->forceFill(['journal_entry_id' => $entry->id])->save();

            // On the statement by definition, so it starts ticked.
            $bankLine = $entry->lines()
                ->where('account_id', $reconciliation->glAccount()->id)->firstOrFail();

            BankReconciliationLine::query()->firstOrCreate([
                'journal_line_id' => $bankLine->id,
            ], [
                'bank_reconciliation_id' => $reconciliation->id,
            ]);

            $this->audit->log(
                action: 'bank_statement_item_recorded',
                subject: $item,
                newValue: [
                    'rekonsiliasi' => $reconciliation->nomor,
                    'keterangan' => trim($keterangan),
                    'arah' => $arah->value,
                    'amount_rupiah' => $amountRupiah,
                    'akun' => $account->kode,
                ],
                actor: $actor,
            );

            return $item->refresh();
        });
    }

    /**
     * The reconciliation statement as it stands right now.
     *
     * Recomputed on every call from the ticks rather than stored, so the screen
     * cannot show a stale difference while somebody is working. The figures are
     * frozen onto the record only at finalisation — see `finalise()` for why
     * that matters.
     */
    public function summarise(BankReconciliation $reconciliation): ReconciliationSummary
    {
        /*
         * The whole account's balance, not the bound region's share of it.
         *
         * This figure is compared against a number typed off a piece of paper
         * from the bank, and the bank has never heard of our regions: every
         * movement on the account is on that statement whichever books
         * recorded it. `bank_accounts` carries no region for the same reason —
         * there is one company account, the one printed on every faktur.
         *
         * The neraca is the other question and stays region-scoped: what this
         * region's books say about cash. Both are right; only one of them can
         * be reconciled against a bank.
         */
        $saldoBuku = $this->ledger->balanceAcrossRegions(
            $reconciliation->glAccount()->kode,
            $reconciliation->tanggal_rekening,
        );

        $ticked = $this->tickedLineIds($reconciliation);

        $setoran = 0;
        $penarikan = 0;
        $belum = 0;

        foreach ($this->candidateLines($reconciliation) as $line) {
            if (isset($ticked[$line->id])) {
                continue;
            }

            $belum++;

            // Debits to Bank are money in that the bank has not seen; credits
            // are money out it has not paid. Signs matter — see the summary.
            $setoran += (int) $line->debit_rupiah;
            $penarikan += (int) $line->kredit_rupiah;
        }

        $diharapkan = $saldoBuku - $setoran + $penarikan;

        return new ReconciliationSummary(
            saldoBuku: $saldoBuku,
            setoranBeredar: $setoran,
            penarikanBeredar: $penarikan,
            saldoDiharapkan: $diharapkan,
            saldoRekening: (int) $reconciliation->saldo_rekening_rupiah,
            selisih: $diharapkan - (int) $reconciliation->saldo_rekening_rupiah,
            belumDicentang: $belum,
        );
    }

    /**
     * Sign it off.
     *
     * **Refused while any difference remains**, and that strictness is the
     * feature. A reconciliation that can be finalised with "Rp 43.500
     * unexplained" is a reconciliation nobody will chase, and three months
     * later the unexplained figure is Rp 900.000 and nobody knows when it
     * started. If a difference genuinely cannot be traced, the honest move is
     * to record it as a statement item against an expense account — which
     * leaves the fudge visible in the books with a name on it, instead of
     * invisible in a status field.
     *
     * The summary is frozen onto the record here. A reconciliation is a claim
     * about a moment, and recomputing it later against a ledger that has since
     * moved would quietly rewrite what was signed off.
     */
    public function finalise(BankReconciliation $reconciliation, User $actor): BankReconciliation
    {
        $this->assertMayReconcile($actor);
        $this->assertDraft($reconciliation);

        $summary = $this->summarise($reconciliation);

        if (! $summary->isReconciled()) {
            throw new DomainException(sprintf(
                'Masih ada selisih %s yang belum dijelaskan. %s',
                number_format(abs($summary->selisih), 0, ',', '.'),
                $summary->hint() ?? '',
            ));
        }

        return DB::transaction(function () use ($reconciliation, $actor, $summary) {
            // Re-read entity-wide: the row may have been stamped in another
            // region's books by whoever opened it, and finalising must not
            // depend on who is signing.
            $locked = BankReconciliation::query()
                ->withoutGlobalScope('region')
                ->lockForUpdate()
                ->findOrFail($reconciliation->id);

            $this->assertDraft($locked);

            $locked->forceFill([
                'saldo_buku_rupiah' => $summary->saldoBuku,
                'setoran_beredar_rupiah' => $summary->setoranBeredar,
                'penarikan_beredar_rupiah' => $summary->penarikanBeredar,
                'selisih_rupiah' => $summary->selisih,
                'status' => BankReconciliation::STATUS_SELESAI,
                'finalised_by' => $actor->id,
                'finalised_at' => now(),
            ])->save();

            $this->audit->log(
                action: 'bank_reconciliation_finalised',
                subject: $locked,
                newValue: [
                    'nomor' => $locked->nomor,
                    'tanggal_rekening' => $locked->tanggal_rekening->toDateString(),
                    'saldo_buku_rupiah' => $summary->saldoBuku,
                    'saldo_rekening_rupiah' => $summary->saldoRekening,
                    'setoran_beredar_rupiah' => $summary->setoranBeredar,
                    'penarikan_beredar_rupiah' => $summary->penarikanBeredar,
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    /** The most recent statement that has been signed off, if any. */
    public function lastFinalised(?BankAccount $rekening = null): ?BankReconciliation
    {
        // Entity-wide: "when was this account last proven" is a question
        // about the account, and the account is the company's.
        return BankReconciliation::query()
            ->withoutGlobalScope('region')
            ->finalised()
            ->when($rekening !== null, fn ($q) => $q->where('bank_account_id', $rekening->id))
            ->orderByDesc('tanggal_rekening')
            ->first();
    }

    /**
     * How long the bank account has gone unproven.
     *
     * Null when it has never been reconciled at all, which is a different and
     * worse answer than a large number — and the one a new installation gives.
     */
    public function daysSinceLastReconciled(?BankAccount $rekening = null): ?int
    {
        $last = $this->lastFinalised($rekening);

        return $last === null
            ? null
            : (int) $last->tanggal_rekening->diffInDays(Carbon::now()->startOfDay());
    }

    /**
     * A line from another reconciliation cannot be ticked here.
     *
     * The unique index on `journal_line_id` would refuse it anyway; this
     * refuses it with a sentence, and catches the other case the index cannot
     * see — a line dated after the statement, which no amount of ticking makes
     * true.
     */
    private function assertIsCandidate(BankReconciliation $reconciliation, JournalLine $line): void
    {
        $claimed = BankReconciliationLine::query()
            ->where('journal_line_id', $line->id)
            ->where('bank_reconciliation_id', '!=', $reconciliation->id)
            ->exists();

        if ($claimed) {
            throw new DomainException('Baris ini sudah dicentang di rekonsiliasi lain.');
        }

        if ((int) $line->account_id !== $reconciliation->glAccount()->id) {
            throw new DomainException('Baris ini bukan milik rekening yang sedang direkonsiliasi.');
        }

        $tanggal = $line->entry?->tanggal;

        if ($tanggal !== null && $tanggal->greaterThan($reconciliation->tanggal_rekening)) {
            throw new DomainException(
                'Baris ini bertanggal setelah rekening koran, jadi tidak mungkin ada di dalamnya.'
            );
        }
    }

    private function assertMayReconcile(User $actor): void
    {
        if (! $actor->role()->canReconcileBank()) {
            throw new DomainException('Anda tidak berhak melakukan rekonsiliasi bank.');
        }
    }

    private function assertDraft(BankReconciliation $reconciliation): void
    {
        if (! $reconciliation->isDraft()) {
            throw new DomainException(
                "Rekonsiliasi {$reconciliation->nomor} sudah selesai dan tidak bisa diubah lagi."
            );
        }
    }
}
