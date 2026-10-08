<?php

declare(strict_types=1);

namespace App\Domain\Posting;

use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Account balances from the journal: Σ(debit − credit) over active postings,
 * shown with the account's normal sign, parents summing their children.
 */
final class AccountBalances
{
    /** @return array<int, int> account id → balance */
    public static function asOf(DateTimeInterface|string|null $date = null, ?int $branchId = null): array
    {
        $raw = JournalLine::query()
            ->active()
            ->when($date, fn ($q) => $q->where('journal_lines.trans_date', '<=', Carbon::parse($date)->toDateString()))
            ->when($branchId, fn ($q) => $q->where('journal_lines.branch_id', $branchId))
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit - journal_lines.credit) AS net')
            ->groupBy('journal_lines.account_id')
            ->pluck('net', 'account_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $accounts = Account::query()->get(['id', 'parent_id', 'account_type'])->keyBy('id');
        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account->id] = $raw[$account->id] ?? 0;
        }
        // Roll children up into their parents, deepest first.
        foreach ($accounts->sortByDesc(fn ($a) => self::depth($a, $accounts)) as $account) {
            if ($account->parent_id !== null && isset($balances[$account->parent_id])) {
                $balances[$account->parent_id] += $balances[$account->id];
            }
        }
        foreach ($accounts as $account) {
            if (! $account->account_type->isDebitNormal()) {
                $balances[$account->id] = -$balances[$account->id];
            }
        }

        return $balances;
    }

    private static function depth($account, $accounts): int
    {
        $depth = 0;
        while ($account->parent_id !== null && isset($accounts[$account->parent_id])) {
            $account = $accounts[$account->parent_id];
            $depth++;
        }

        return $depth;
    }
}
