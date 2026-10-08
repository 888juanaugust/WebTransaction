<?php

declare(strict_types=1);

namespace App\Domain\CashBank;

use App\Domain\Audit\Auditor;
use App\Domain\Pengaturan\BusinessRule;
use App\Domain\Shared\Format;
use App\Models\CashBank\BankReconciliation;
use App\Models\CashBank\BankReconciliationItem;
use App\Models\CashBank\BankStatementLine;
use App\Models\GeneralLedger\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bank reconciliation (K-05): the book lines of one bank account over one
 * period are cleared against the statement, by hand or matched to imported
 * statement lines; the reconciliation closes when the cleared balance meets
 * the statement's ending balance. A cleared line locks its document. Under
 * segregation of duties nobody clears a line of a document they entered.
 * Every step is in the Activity Log.
 */
final class Reconciler
{
    public const MATCH_WINDOW_DAYS = 3;

    public function open(int $bankAccountId, CarbonImmutable|string $start, CarbonImmutable|string $end, int $statementBalance = 0): BankReconciliation
    {
        $start = CarbonImmutable::parse($start)->toDateString();
        $end = CarbonImmutable::parse($end)->toDateString();
        if ($end < $start) {
            throw new RuntimeException(__('The period ends before it starts.'));
        }

        $reconciliation = BankReconciliation::query()->firstOrCreate(
            ['bank_account_id' => $bankAccountId, 'start_date' => $start, 'end_date' => $end],
            ['statement_balance' => $statementBalance, 'status' => BankReconciliation::OPEN, 'created_by' => auth()->id()],
        );
        if ($reconciliation->wasRecentlyCreated) {
            $this->log('bank_reconciliation_opened', $reconciliation, ['statement_balance' => $statementBalance]);
        } elseif (! $reconciliation->isClosed() && $reconciliation->statement_balance !== $statementBalance) {
            $before = $reconciliation->statement_balance;
            $reconciliation->forceFill(['statement_balance' => $statementBalance])->save();
            $this->log('bank_statement_balance_changed', $reconciliation, ['before' => $before, 'after' => $statementBalance]);
        }

        return $reconciliation;
    }

    /** Book lines of the account up to the period's end: those not yet cleared, and those cleared in this reconciliation. */
    public function bookLines(BankReconciliation $reconciliation): Collection
    {
        return JournalLine::query()->active()
            ->with(['entry', 'posting'])
            ->where('account_id', $reconciliation->bank_account_id)
            ->where('trans_date', '<=', $reconciliation->end_date)
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('reconciliationItem')
                ->orWhereHas('reconciliationItem', fn (Builder $i) => $i->where('bank_reconciliation_id', $reconciliation->id)))
            ->orderBy('trans_date')->orderBy('id')
            ->get();
    }

    /** @param  list<int>  $journalLineIds */
    public function clear(BankReconciliation $reconciliation, array $journalLineIds, ?int $statementLineId = null, ?int $userId = null): int
    {
        $this->assertOpen($reconciliation);
        $cleared = DB::transaction(fn (): array => $this->clearLines($reconciliation, $journalLineIds, $statementLineId, $userId ?? auth()->id()));
        if ($cleared !== []) {
            $this->log('bank_lines_cleared', $reconciliation, ['journal_lines' => $cleared, 'statement_line' => $statementLineId]);
        }

        return count($cleared);
    }

    /**
     * @param  list<int>  $journalLineIds
     * @return list<int> the lines cleared
     */
    private function clearLines(BankReconciliation $reconciliation, array $journalLineIds, ?int $statementLineId, ?int $userId): array
    {
        $cleared = [];
        foreach (array_unique($journalLineIds) as $id) {
            $line = JournalLine::query()->active()->with('posting.document')->where('account_id', $reconciliation->bank_account_id)->find($id);
            if ($line === null || $line->trans_date->gt($reconciliation->end_date) || $this->isCleared($line)) {
                continue;
            }
            if ($this->enteredBy($line, $userId)) {
                throw new RuntimeException(__('Segregation of duties: :number was entered by you; someone else clears it.', ['number' => $line->posting?->document?->getAttribute('number') ?? $line->id]));
            }
            BankReconciliationItem::query()->create([
                'bank_reconciliation_id' => $reconciliation->id,
                'journal_line_id' => $line->id,
                'bank_statement_line_id' => $statementLineId,
                'cleared_on' => $reconciliation->end_date,
                'cleared_by' => $userId,
                'created_at' => now(),
            ]);
            $cleared[] = (int) $line->id;
        }

        return $cleared;
    }

    /** Under segregation of duties, whoever entered a line's document does not clear it. */
    private function enteredBy(JournalLine $line, ?int $userId): bool
    {
        if ($userId === null || ! BusinessRule::SegregationOfDuties->isOn()) {
            return false;
        }
        $creator = $line->posting?->document?->getAttribute('created_by');

        return $creator !== null && (int) $creator === $userId;
    }

    /** Clears every book line that an unmatched statement line of the same amount explains, within a few days. */
    public function autoMatch(BankReconciliation $reconciliation, ?int $userId = null): int
    {
        $this->assertOpen($reconciliation);
        $userId ??= auth()->id();
        $matched = [];
        DB::transaction(function () use ($reconciliation, $userId, &$matched): void {
            $statementLines = BankStatementLine::query()->unmatched()
                ->where('bank_account_id', $reconciliation->bank_account_id)
                ->where('trans_date', '<=', CarbonImmutable::parse($reconciliation->end_date)->addDays(self::MATCH_WINDOW_DAYS))
                ->orderBy('trans_date')->orderBy('id')
                ->get();
            $used = [];
            foreach ($this->bookLines($reconciliation)->load('posting.document') as $line) {
                if ($this->isCleared($line) || $this->enteredBy($line, $userId)) {
                    continue; // a line the user entered waits for someone else
                }
                $signed = $line->debit - $line->credit;
                $candidate = $statementLines->first(fn (BankStatementLine $s) => ! isset($used[$s->id])
                    && $s->amount === $signed
                    && abs($s->trans_date->diffInDays($line->trans_date, false)) <= self::MATCH_WINDOW_DAYS);
                if ($candidate === null) {
                    continue;
                }
                $used[$candidate->id] = true;
                foreach ($this->clearLines($reconciliation, [$line->id], $candidate->id, $userId) as $id) {
                    $matched[$id] = $candidate->id;
                }
            }
        });
        if ($matched !== []) {
            $this->log('bank_lines_matched', $reconciliation, ['journal_line_to_statement_line' => $matched]);
        }

        return count($matched);
    }

    /** @return array{book_balance: int, cleared_balance: int, uncleared: int, statement_balance: int, difference: int} */
    public function summary(BankReconciliation $reconciliation): array
    {
        $book = (int) JournalLine::query()->active()
            ->where('account_id', $reconciliation->bank_account_id)
            ->where('trans_date', '<=', $reconciliation->end_date)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')->value('net');
        $cleared = (int) JournalLine::query()->active()
            ->where('account_id', $reconciliation->bank_account_id)
            ->where('trans_date', '<=', $reconciliation->end_date)
            ->whereHas('reconciliationItem')
            ->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')->value('net');

        return [
            'book_balance' => $book,
            'cleared_balance' => $cleared,
            'uncleared' => $book - $cleared,
            'statement_balance' => (int) $reconciliation->statement_balance,
            'difference' => (int) $reconciliation->statement_balance - $cleared,
        ];
    }

    public function close(BankReconciliation $reconciliation, ?int $userId = null): void
    {
        $this->assertOpen($reconciliation);
        $summary = $this->summary($reconciliation);
        if ($summary['difference'] !== 0) {
            throw new RuntimeException(__('The cleared balance differs from the statement by :amount; clear or correct before closing.', ['amount' => Format::number(abs($summary['difference']))]));
        }
        $reconciliation->forceFill(['status' => BankReconciliation::CLOSED, 'closed_at' => now(), 'closed_by' => $userId ?? auth()->id()])->save();
        Auditor::log('bank_reconciled', $reconciliation, $reconciliation->bankAccount?->name, $summary, $reconciliation->end_date->toDateString());
    }

    public function isCleared(JournalLine|int $line): bool
    {
        $id = $line instanceof JournalLine ? $line->id : $line;

        return BankReconciliationItem::query()->where('journal_line_id', $id)->exists();
    }

    /** @param  array<string, mixed>  $meta */
    private function log(string $action, BankReconciliation $reconciliation, array $meta): void
    {
        Auditor::log($action, $reconciliation, $reconciliation->bankAccount?->name, $meta, $reconciliation->end_date->toDateString());
    }

    private function assertOpen(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->isClosed()) {
            throw new RuntimeException(__('This reconciliation is closed.'));
        }
    }
}
