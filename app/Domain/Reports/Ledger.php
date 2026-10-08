<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Shared\Enums\AccountType;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The sums every financial report is built from: net movement per account
 * over a period and the balance before it, from the lines of active postings
 * only, parents rolled up from their children, shown with the normal sign.
 */
final class Ledger
{
    /** @return Collection<int, Account> every account in chart order, keyed by id */
    public static function accounts(): Collection
    {
        return Account::query()->orderBy('no')->get()->keyBy('id');
    }

    /** @return array<int, int> account id → Σ(debit − credit) of lines dated before the period */
    public static function openingNet(Period $period): array
    {
        return self::rollUp(self::lines($period)->where('journal_lines.trans_date', '<', $period->fromDate()));
    }

    /** @return array{debit: array<int,int>, credit: array<int,int>} per account, within the period */
    public static function movement(Period $period): array
    {
        $rows = self::lines($period)
            ->whereBetween('journal_lines.trans_date', [$period->fromDate(), $period->untilDate()])
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->groupBy('journal_lines.account_id')
            ->get();
        $debit = [];
        $credit = [];
        foreach ($rows as $row) {
            $debit[(int) $row->account_id] = (int) $row->debit;
            $credit[(int) $row->account_id] = (int) $row->credit;
        }

        return ['debit' => self::rollUpMap($debit), 'credit' => self::rollUpMap($credit)];
    }

    /** @return array<int, int> account id → Σ(debit − credit) up to and including the period's end */
    public static function closingNet(Period $period): array
    {
        return self::rollUp(self::lines($period)->where('journal_lines.trans_date', '<=', $period->untilDate()));
    }

    /** Net of the period's income statement accounts: revenue + other income − cost of sales − expenses − other expenses. */
    public static function netIncome(array $net, Collection $accounts): int
    {
        $income = 0;
        foreach ($accounts as $account) {
            if ($account->parent_id !== null || $account->account_type->isBalanceSheet()) {
                continue;
            }
            $income -= $net[$account->id] ?? 0; // credit-side accounts carry a negative net
        }

        return $income;
    }

    /** A signed net shown on the account's normal side. */
    public static function normal(Account $account, int $net): int
    {
        return $account->account_type->isDebitNormal() ? $net : -$net;
    }

    /** @return list<AccountType> */
    public static function typesIn(string $section): array
    {
        return match ($section) {
            'current_assets' => [AccountType::CashBank, AccountType::AccountsReceivable, AccountType::Inventory, AccountType::OtherCurrentAsset],
            'non_current_assets' => [AccountType::FixedAsset, AccountType::AccumulatedDepreciation, AccountType::OtherAsset],
            'current_liabilities' => [AccountType::AccountsPayable, AccountType::OtherCurrentLiability],
            'non_current_liabilities' => [AccountType::LongTermLiability],
            'equity' => [AccountType::Equity],
            'revenue' => [AccountType::Revenue],
            'cost_of_sales' => [AccountType::CostOfSales],
            'expenses' => [AccountType::Expense],
            'other_income' => [AccountType::OtherIncome],
            'other_expenses' => [AccountType::OtherExpense],
            default => [],
        };
    }

    private static function lines(Period $period): Builder
    {
        return $period->applyTo(JournalLine::query()->active());
    }

    /** @return array<int, int> */
    private static function rollUp(Builder $query): array
    {
        $net = $query->selectRaw('journal_lines.account_id, SUM(journal_lines.debit - journal_lines.credit) AS net')
            ->groupBy('journal_lines.account_id')
            ->pluck('net', 'account_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return self::rollUpMap($net);
    }

    /** Children sum into their parents, deepest first. @param array<int,int> $values */
    private static function rollUpMap(array $values): array
    {
        $accounts = Account::query()->get(['id', 'parent_id'])->keyBy('id');
        $out = [];
        foreach ($accounts as $account) {
            $out[$account->id] = $values[$account->id] ?? 0;
        }
        $depth = function ($account) use ($accounts): int {
            $d = 0;
            while ($account->parent_id !== null && isset($accounts[$account->parent_id])) {
                $account = $accounts[$account->parent_id];
                $d++;
            }

            return $d;
        };
        foreach ($accounts->sortByDesc($depth) as $account) {
            if ($account->parent_id !== null && isset($out[$account->parent_id])) {
                $out[$account->parent_id] += $out[$account->id];
            }
        }

        return $out;
    }
}
