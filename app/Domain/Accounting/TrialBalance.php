<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\Account;
use App\Models\JournalLine;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Neraca saldo — every account, both columns, as at a date.
 *
 * This is the report that checks the others. If the two totals differ, no
 * balance sheet built on the same data means anything, and the difference is
 * the first thing to chase. It should be impossible: Ledger refuses an
 * unbalanced entry and nothing else writes to these tables. Which is exactly
 * why it is worth printing — the check is cheap and the assumption behind it
 * is worth a lot.
 *
 * Built in one query. A chart of twenty accounts and a ledger of a hundred
 * thousand lines should not be twenty scans.
 */
final class TrialBalance
{
    /** @param  list<TrialBalanceRow>  $rows */
    private function __construct(
        public readonly ?Carbon $asOf,
        private readonly array $rows,
    ) {}

    /** Everything up to and including a date, or everything ever. */
    public static function asOf(?DateTimeInterface $tanggal = null): self
    {
        return self::build($tanggal === null ? null : Carbon::parse($tanggal), null);
    }

    /**
     * Movement within a window rather than a running total.
     *
     * This is what an income statement reads: "what did we earn in August" is
     * a question about the movement between two dates, where "what do we own"
     * is a question about the balance at one. Same query, one extra bound.
     */
    public static function forPeriod(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return self::build(Carbon::parse($to), Carbon::parse($from));
    }

    private static function build(?Carbon $asOf, ?Carbon $from): self
    {
        $totals = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($asOf !== null, fn ($q) => $q->whereDate('journal_entries.tanggal', '<=', $asOf))
            ->when($from !== null, fn ($q) => $q->whereDate('journal_entries.tanggal', '>=', $from))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(debit_rupiah) AS d, SUM(kredit_rupiah) AS k')
            ->get()
            ->keyBy('account_id');

        /*
         * Every postable account appears, including the ones with nothing in
         * them. An account that is missing from a report reads as an account
         * that does not exist, and "where did Utang Belum Ditagih go" is a
         * worse question than a row of zeroes.
         */
        $rows = Account::query()
            ->where('dapat_diposting', true)
            ->orderBy('kode')
            ->get()
            ->map(fn (Account $account) => new TrialBalanceRow(
                account: $account,
                debit: (int) ($totals->get($account->id)->d ?? 0),
                kredit: (int) ($totals->get($account->id)->k ?? 0),
            ))
            ->all();

        return new self($asOf, $rows);
    }

    /** @return list<TrialBalanceRow> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<TrialBalanceRow> */
    public function rowsWithActivity(): array
    {
        return array_values(array_filter($this->rows, fn (TrialBalanceRow $r) => ! $r->isEmpty()));
    }

    /** @return list<TrialBalanceRow> */
    public function rowsOfType(AccountType $tipe): array
    {
        return array_values(array_filter($this->rows, fn (TrialBalanceRow $r) => $r->account->tipe === $tipe));
    }

    public function totalDebit(): int
    {
        return array_sum(array_map(fn (TrialBalanceRow $r) => $r->debit, $this->rows));
    }

    public function totalKredit(): int
    {
        return array_sum(array_map(fn (TrialBalanceRow $r) => $r->kredit, $this->rows));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalKredit();
    }

    public function difference(): int
    {
        return $this->totalDebit() - $this->totalKredit();
    }

    /** The balance of one account, in its own direction. */
    public function balanceOf(string $kode): int
    {
        foreach ($this->rows as $row) {
            if ($row->account->kode === $kode) {
                return $row->balance();
            }
        }

        throw new \RuntimeException("Akun {$kode} tidak ada di bagan akun.");
    }

    /** Summed in each type's own direction — assets positive, liabilities positive. */
    public function totalOfType(AccountType $tipe): int
    {
        return array_sum(array_map(
            fn (TrialBalanceRow $r) => $r->balance(),
            $this->rowsOfType($tipe),
        ));
    }
}
