<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use Filament\Support\Contracts\HasLabel;

/** The sixteen account types of the standard chart of accounts. */
enum AccountType: string implements HasLabel
{
    case CashBank = 'cash_bank';
    case AccountsReceivable = 'accounts_receivable';
    case Inventory = 'inventory';
    case OtherCurrentAsset = 'other_current_asset';
    case FixedAsset = 'fixed_asset';
    case AccumulatedDepreciation = 'accumulated_depreciation';
    case OtherAsset = 'other_asset';
    case AccountsPayable = 'accounts_payable';
    case OtherCurrentLiability = 'other_current_liability';
    case LongTermLiability = 'long_term_liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case CostOfSales = 'cost_of_sales';
    case Expense = 'expense';
    case OtherIncome = 'other_income';
    case OtherExpense = 'other_expense';

    public function getLabel(): string
    {
        return match ($this) {
            self::CashBank => __('Cash / Bank'),
            self::AccountsReceivable => __('Accounts Receivable'),
            self::Inventory => __('Inventory'),
            self::OtherCurrentAsset => __('Other Current Asset'),
            self::FixedAsset => __('Fixed Asset'),
            self::AccumulatedDepreciation => __('Accumulated Depreciation'),
            self::OtherAsset => __('Other Asset'),
            self::AccountsPayable => __('Accounts Payable'),
            self::OtherCurrentLiability => __('Other Current Liability'),
            self::LongTermLiability => __('Long-term Liability'),
            self::Equity => __('Equity'),
            self::Revenue => __('Revenue'),
            self::CostOfSales => __('Cost of Sales'),
            self::Expense => __('Expense'),
            self::OtherIncome => __('Other Income'),
            self::OtherExpense => __('Other Expense'),
        };
    }

    /** Whether the account grows on the debit side. */
    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::CashBank, self::AccountsReceivable, self::Inventory, self::OtherCurrentAsset,
            self::FixedAsset, self::OtherAsset, self::CostOfSales, self::Expense, self::OtherExpense => true,
            default => false,
        };
    }

    public function isBalanceSheet(): bool
    {
        return ! in_array($this, [self::Revenue, self::CostOfSales, self::Expense, self::OtherIncome, self::OtherExpense], true);
    }
}
