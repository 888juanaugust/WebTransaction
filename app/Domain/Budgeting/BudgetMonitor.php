<?php

declare(strict_types=1);

namespace App\Domain\Budgeting;

use App\Domain\Reports\Ledger;
use App\Domain\Reports\Period;
use App\Models\Budgeting\BudgetLine;
use App\Models\GeneralLedger\Account;
use Carbon\CarbonImmutable;

/** Budget against actual per account over a span of months (G-08), the actual from the journal. */
final class BudgetMonitor
{
    /** @return list<array{id: int|string, no: string, name: string, budget: int, actual: int, remaining: int, used_percent: ?float}> */
    public static function rows(int $year, ?int $month = null, ?int $accountId = null, ?int $branchId = null): array
    {
        $from = CarbonImmutable::create($year, $month ?? 1, 1);
        $until = $month ? $from->endOfMonth() : $from->endOfYear();
        $period = new Period($from, $until, $branchId);
        $movement = Ledger::movement($period);

        $budgets = BudgetLine::query()
            ->whereHas('budget', fn ($q) => $q->where('year', $year)->when($month, fn ($b) => $b->where('month', $month)))
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->selectRaw('account_id, SUM(amount) AS amount')->groupBy('account_id')->pluck('amount', 'account_id')
            ->map(fn ($v) => (int) $v)->all();

        $rows = [];
        $sum = ['budget' => 0, 'actual' => 0];
        foreach (Account::query()->orderBy('no')->get() as $account) {
            if ($account->account_type->isBalanceSheet() || ($accountId && $account->id !== $accountId)) {
                continue;
            }
            $budget = $budgets[$account->id] ?? 0;
            $actual = Ledger::normal($account, ($movement['debit'][$account->id] ?? 0) - ($movement['credit'][$account->id] ?? 0));
            if ($budget === 0 && $actual === 0) {
                continue;
            }
            $rows[] = ['id' => $account->id, 'no' => $account->no, 'name' => $account->name, 'budget' => $budget, 'actual' => $actual, 'remaining' => $budget - $actual, 'used_percent' => $budget !== 0 ? round($actual * 100 / $budget, 1) : null];
            $sum['budget'] += $budget;
            $sum['actual'] += $actual;
        }
        $rows[] = ['id' => 'total', 'no' => '', 'name' => 'Total', 'budget' => $sum['budget'], 'actual' => $sum['actual'], 'remaining' => $sum['budget'] - $sum['actual'], 'used_percent' => $sum['budget'] !== 0 ? round($sum['actual'] * 100 / $sum['budget'], 1) : null, 'is_total' => true];

        return $rows;
    }
}
