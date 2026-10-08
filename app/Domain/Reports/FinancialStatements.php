<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Company\FiscalYear;
use App\Domain\Shared\Enums\AccountType;
use App\Domain\Shared\Money;
use App\Models\GeneralLedger\Account;
use App\Models\GeneralLedger\JournalLine;

/**
 * Balance sheet (R-01), income statement (R-02), trial balance (R-03) and
 * the statement of changes in equity (R-06), from the ledger alone.
 */
final class FinancialStatements
{
    /**
     * @return list<array{id: string, section: string, no: string, name: string, level: int, amount: int, is_total: bool}>
     */
    public static function balanceSheet(Period $period): array
    {
        $accounts = Ledger::accounts();
        $net = Ledger::closingNet($period);
        $rows = [];
        $totals = [];
        foreach (['current_assets' => __('Current assets'), 'non_current_assets' => __('Non-current assets'), 'current_liabilities' => __('Current liabilities'), 'non_current_liabilities' => __('Non-current liabilities'), 'equity' => __('Equity')] as $section => $label) {
            $rows[] = ['id' => "h-{$section}", 'section' => $section, 'no' => '', 'name' => $label, 'level' => 0, 'amount' => 0, 'is_total' => false, 'is_heading' => true];
            $sum = 0;
            foreach ($accounts as $account) {
                if (! in_array($account->account_type, Ledger::typesIn($section), true)) {
                    continue;
                }
                $amount = Ledger::normal($account, $net[$account->id] ?? 0);
                if ($account->account_type === AccountType::AccumulatedDepreciation) {
                    $amount = -$amount; // a contra-asset: shown against the assets it depreciates
                }
                if ($amount === 0) {
                    continue;
                }
                $rows[] = ['id' => (string) $account->id, 'section' => $section, 'no' => $account->no, 'name' => $account->name, 'level' => $account->parent_id ? 2 : 1, 'amount' => $amount, 'is_total' => false, 'is_heading' => false];
                if ($account->parent_id === null) {
                    $sum += $amount;
                }
            }
            if ($section === 'equity') {
                // Income of the fiscal years before this one is retained earnings; this year's stands apart.
                $income = Ledger::netIncome($net, $accounts);
                $yearStart = FiscalYear::startOf($period->untilDate());
                $retained = Ledger::netIncome(Ledger::closingNet($period->withDates($yearStart->subYears(100), $yearStart->subDay())), $accounts);
                if ($retained !== 0) {
                    $rows[] = ['id' => 'retained-earnings', 'section' => $section, 'no' => '', 'name' => __('Retained earnings'), 'level' => 1, 'amount' => $retained, 'is_total' => false, 'is_heading' => false];
                }
                $rows[] = ['id' => 'net-income', 'section' => $section, 'no' => '', 'name' => __('Net income this year'), 'level' => 1, 'amount' => $income - $retained, 'is_total' => false, 'is_heading' => false];
                $sum += $income;
            }
            $totals[$section] = $sum;
            $rows[] = ['id' => "t-{$section}", 'section' => $section, 'no' => '', 'name' => "Total {$label}", 'level' => 0, 'amount' => $sum, 'is_total' => true, 'is_heading' => false];
        }
        $assets = $totals['current_assets'] + $totals['non_current_assets'];
        $liabilitiesEquity = $totals['current_liabilities'] + $totals['non_current_liabilities'] + $totals['equity'];
        $rows[] = ['id' => 't-assets', 'section' => 'summary', 'no' => '', 'name' => __('Total assets'), 'level' => 0, 'amount' => $assets, 'is_total' => true, 'is_heading' => false];
        $rows[] = ['id' => 't-liabilities-equity', 'section' => 'summary', 'no' => '', 'name' => __('Total liabilities and equity'), 'level' => 0, 'amount' => $liabilitiesEquity, 'is_total' => true, 'is_heading' => false];

        return $rows;
    }

    /** @return list<array{id: string, no: string, name: string, level: int, amount: int, is_total: bool, is_heading: bool}> */
    public static function incomeStatement(Period $period): array
    {
        $accounts = Ledger::accounts();
        $movement = Ledger::movement($period);
        $net = [];
        foreach ($movement['debit'] as $id => $debit) {
            $net[$id] = $debit - ($movement['credit'][$id] ?? 0);
        }
        $rows = [];
        $sums = [];
        foreach (['revenue' => __('Revenue'), 'cost_of_sales' => __('Cost of sales'), 'expenses' => __('Operating expenses'), 'other_income' => __('Other income'), 'other_expenses' => __('Other expenses')] as $section => $label) {
            $rows[] = ['id' => "h-{$section}", 'no' => '', 'name' => $label, 'level' => 0, 'amount' => 0, 'is_total' => false, 'is_heading' => true];
            $sum = 0;
            foreach ($accounts as $account) {
                if (! in_array($account->account_type, Ledger::typesIn($section), true)) {
                    continue;
                }
                $amount = Ledger::normal($account, $net[$account->id] ?? 0);
                if ($amount === 0) {
                    continue;
                }
                $rows[] = ['id' => (string) $account->id, 'no' => $account->no, 'name' => $account->name, 'level' => $account->parent_id ? 2 : 1, 'amount' => $amount, 'is_total' => false, 'is_heading' => false];
                if ($account->parent_id === null) {
                    $sum += $amount;
                }
            }
            $sums[$section] = $sum;
            $rows[] = ['id' => "t-{$section}", 'no' => '', 'name' => "Total {$label}", 'level' => 0, 'amount' => $sum, 'is_total' => true, 'is_heading' => false];
            if ($section === 'cost_of_sales') {
                $rows[] = ['id' => 't-gross', 'no' => '', 'name' => __('Gross profit'), 'level' => 0, 'amount' => $sums['revenue'] - $sum, 'is_total' => true, 'is_heading' => false];
            }
            if ($section === 'expenses') {
                $rows[] = ['id' => 't-operating', 'no' => '', 'name' => __('Operating income'), 'level' => 0, 'amount' => $sums['revenue'] - $sums['cost_of_sales'] - $sum, 'is_total' => true, 'is_heading' => false];
            }
        }
        $rows[] = ['id' => 't-net', 'no' => '', 'name' => __('Net income'), 'level' => 0, 'amount' => $sums['revenue'] - $sums['cost_of_sales'] - $sums['expenses'] + $sums['other_income'] - $sums['other_expenses'], 'is_total' => true, 'is_heading' => false];

        return $rows;
    }

    /** @return list<array{id: int|string, no: string, name: string, opening: int, debit: int, credit: int, closing: int}> */
    public static function trialBalance(Period $period): array
    {
        $accounts = Ledger::accounts();
        $opening = Ledger::openingNet($period);
        $movement = Ledger::movement($period);
        $rows = [];
        $sum = ['opening_debit' => 0, 'opening_credit' => 0, 'debit' => 0, 'credit' => 0, 'closing_debit' => 0, 'closing_credit' => 0];
        foreach ($accounts as $account) {
            if ($account->parent_id !== null) {
                continue; // one line per top-level account, children rolled in
            }
            $open = $opening[$account->id] ?? 0;
            $debit = $movement['debit'][$account->id] ?? 0;
            $credit = $movement['credit'][$account->id] ?? 0;
            $close = $open + $debit - $credit;
            if ($open === 0 && $debit === 0 && $credit === 0) {
                continue;
            }
            $rows[] = ['id' => $account->id, 'no' => $account->no, 'name' => $account->name, 'opening' => $open, 'debit' => $debit, 'credit' => $credit, 'closing' => $close];
            $sum['opening_debit'] += max(0, $open);
            $sum['opening_credit'] += max(0, -$open);
            $sum['debit'] += $debit;
            $sum['credit'] += $credit;
            $sum['closing_debit'] += max(0, $close);
            $sum['closing_credit'] += max(0, -$close);
        }
        $rows[] = ['id' => 'total', 'no' => '', 'name' => __('Total'), 'opening' => $sum['opening_debit'] - $sum['opening_credit'], 'debit' => $sum['debit'], 'credit' => $sum['credit'], 'closing' => $sum['closing_debit'] - $sum['closing_credit'], 'is_total' => true];

        return $rows;
    }

    /** @return list<array{id: string, name: string, amount: int, is_total: bool}> */
    public static function equityChanges(Period $period): array
    {
        $accounts = Ledger::accounts();
        $opening = Ledger::openingNet($period);
        $movement = Ledger::movement($period);
        $closing = Ledger::closingNet($period);

        $openingEquity = 0;
        $contributions = 0;
        foreach ($accounts as $account) {
            if ($account->parent_id !== null || ! in_array($account->account_type, Ledger::typesIn('equity'), true)) {
                continue;
            }
            $openingEquity += Ledger::normal($account, $opening[$account->id] ?? 0);
            $contributions += ($movement['credit'][$account->id] ?? 0) - ($movement['debit'][$account->id] ?? 0);
        }
        $openingIncome = Ledger::netIncome($opening, $accounts);
        $periodIncome = Ledger::netIncome($closing, $accounts) - $openingIncome;

        return [
            ['id' => 'opening', 'name' => __('Equity at the start of the period (including earlier income)'), 'amount' => $openingEquity + $openingIncome, 'is_total' => false],
            ['id' => 'contributions', 'name' => __('Capital contributed less withdrawn'), 'amount' => $contributions, 'is_total' => false],
            ['id' => 'income', 'name' => __('Net income of the period'), 'amount' => $periodIncome, 'is_total' => false],
            ['id' => 'closing', 'name' => __('Equity at the end of the period'), 'amount' => $openingEquity + $openingIncome + $contributions + $periodIncome, 'is_total' => true],
        ];
    }

    /**
     * Cash flow (R-05), direct method by counter-account: each cash/bank
     * posting's other legs classify the movement as operating, investing or
     * financing.
     *
     * @return list<array{id: string, name: string, amount: int, is_total: bool, is_heading: bool}>
     */
    public static function cashFlow(Period $period): array
    {
        $accounts = Ledger::accounts();
        $cashIds = $accounts->filter(fn (Account $a) => $a->account_type === AccountType::CashBank)->keys()->all();
        $lines = $period->applyTo(JournalLine::query()->active())
            ->whereBetween('journal_lines.trans_date', [$period->fromDate(), $period->untilDate()])
            ->whereIn('journal_lines.posting_id', fn ($q) => $q->select('posting_id')->from('journal_lines')->whereIn('account_id', $cashIds))
            ->get(['posting_id', 'account_id', 'debit', 'credit']);

        $byPosting = $lines->groupBy('posting_id');
        $buckets = ['operating' => [], 'investing' => [], 'financing' => []];
        foreach ($byPosting as $group) {
            $cashNet = 0;
            $counter = [];
            foreach ($group as $line) {
                $net = $line->debit - $line->credit;
                if (in_array($line->account_id, $cashIds, true)) {
                    $cashNet += $net;
                } else {
                    $counter[$line->account_id] = ($counter[$line->account_id] ?? 0) + $net;
                }
            }
            if ($cashNet === 0 || $counter === []) {
                continue; // a transfer between cash accounts, or nothing against cash
            }
            // The cash is split over the other accounts by their size, in whole rupiah that add back up to it.
            $shares = array_sum(array_map('abs', $counter)) > 0 ? Money::allocate($cashNet, array_map('abs', $counter)) : [];
            foreach ($counter as $accountId => $net) {
                $account = $accounts[$accountId] ?? null;
                $section = match ($account?->account_type) {
                    AccountType::FixedAsset, AccountType::AccumulatedDepreciation, AccountType::OtherAsset => 'investing',
                    AccountType::LongTermLiability, AccountType::Equity => 'financing',
                    default => 'operating',
                };
                $share = $shares[$accountId] ?? 0;
                $name = $account ? "{$account->no} {$account->name}" : __('Other');
                $buckets[$section][$name] = ($buckets[$section][$name] ?? 0) + $share;
            }
        }

        $rows = [];
        $total = 0;
        foreach (['operating' => __('Operating activities'), 'investing' => __('Investing activities'), 'financing' => __('Financing activities')] as $section => $label) {
            $rows[] = ['id' => "h-{$section}", 'name' => $label, 'amount' => 0, 'is_total' => false, 'is_heading' => true];
            $sum = 0;
            ksort($buckets[$section]);
            foreach ($buckets[$section] as $name => $amount) {
                $rows[] = ['id' => "{$section}-".md5($name), 'name' => $name, 'amount' => $amount, 'is_total' => false, 'is_heading' => false];
                $sum += $amount;
            }
            $rows[] = ['id' => "t-{$section}", 'name' => __('Net cash from :section', ['section' => mb_strtolower($label)]), 'amount' => $sum, 'is_total' => true, 'is_heading' => false];
            $total += $sum;
        }
        $openingCash = 0;
        foreach (Ledger::openingNet($period) as $id => $net) {
            if (in_array($id, $cashIds, true) && ($accounts[$id]->parent_id ?? null) === null) {
                $openingCash += $net;
            }
        }
        $rows[] = ['id' => 't-net', 'name' => __('Net change in cash'), 'amount' => $total, 'is_total' => true, 'is_heading' => false];
        $rows[] = ['id' => 'opening-cash', 'name' => __('Cash at the start'), 'amount' => $openingCash, 'is_total' => false, 'is_heading' => false];
        $rows[] = ['id' => 'closing-cash', 'name' => __('Cash at the end'), 'amount' => $openingCash + $total, 'is_total' => true, 'is_heading' => false];

        return $rows;
    }
}
