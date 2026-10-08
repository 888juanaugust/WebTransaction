<?php

namespace Database\Seeders\System;

use App\Domain\Pengaturan\Preferensi;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Shared\Enums\AccountType;
use App\Models\GeneralLedger\Account;
use Illuminate\Database\Seeder;

/**
 * A starting chart of accounts: the accounts the system itself posts to
 * (is_system) and the usual operating accounts. The owner renames and extends
 * them on the Chart of Accounts screen.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // no, name, type, system
            ['1100', 'Cash & Bank', AccountType::CashBank, false],
            ['1101', 'Petty Cash', AccountType::CashBank, false],
            ['1102', 'Bank', AccountType::CashBank, false],
            ['1103', 'Pending Payments', AccountType::CashBank, true],
            ['1105', 'Giros Receivable', AccountType::OtherCurrentAsset, true],
            ['1200', 'Accounts Receivable', AccountType::AccountsReceivable, true],
            ['1300', 'Inventory', AccountType::Inventory, true],
            ['1310', 'Goods Delivered, Not Invoiced', AccountType::Inventory, true],
            ['1400', 'VAT In', AccountType::OtherCurrentAsset, true],
            ['1410', 'Vendor Down Payments', AccountType::OtherCurrentAsset, true],
            ['1420', 'Prepaid Income Tax', AccountType::OtherCurrentAsset, false],
            ['1500', 'Fixed Assets', AccountType::FixedAsset, false],
            ['1510', 'Accumulated Depreciation', AccountType::AccumulatedDepreciation, false],
            ['2100', 'Accounts Payable', AccountType::AccountsPayable, true],
            ['2105', 'Giros Payable', AccountType::OtherCurrentLiability, true],
            ['2110', 'Goods Received, Not Invoiced', AccountType::AccountsPayable, true],
            ['2200', 'VAT Out', AccountType::OtherCurrentLiability, true],
            ['2210', 'Customer Down Payments', AccountType::OtherCurrentLiability, true],
            ['2220', 'Withholding Tax Payable', AccountType::OtherCurrentLiability, true],
            ['2230', 'Accrued Expenses', AccountType::OtherCurrentLiability, false],
            ['2240', 'BPJS Payable', AccountType::OtherCurrentLiability, false],
            ['2300', 'Bank Loans', AccountType::LongTermLiability, false],
            ['3100', 'Share Capital', AccountType::Equity, false],
            ['3200', 'Retained Earnings', AccountType::Equity, true],
            ['3300', 'Opening Balance Equity', AccountType::Equity, true],
            ['4100', 'Sales', AccountType::Revenue, true],
            ['4200', 'Sales Returns', AccountType::Revenue, true],
            ['4300', 'Sales Discounts', AccountType::Revenue, true],
            ['5100', 'Cost of Goods Sold', AccountType::CostOfSales, true],
            ['5200', 'Inventory Adjustments', AccountType::CostOfSales, true],
            ['5300', 'Purchase Discounts', AccountType::CostOfSales, true],
            ['6100', 'Salaries & Wages', AccountType::Expense, false],
            ['6110', 'Employee Benefits (BPJS)', AccountType::Expense, false],
            ['6200', 'Rent', AccountType::Expense, false],
            ['6300', 'Freight Out', AccountType::Expense, false],
            ['6400', 'Depreciation Expense', AccountType::Expense, false],
            ['6500', 'Office Expenses', AccountType::Expense, false],
            ['7100', 'Interest Income', AccountType::OtherIncome, false],
            ['7200', 'Rounding Gains', AccountType::OtherIncome, true],
            ['8100', 'Bank Charges', AccountType::OtherExpense, false],
            ['8200', 'Rounding Losses', AccountType::OtherExpense, true],
            ['7300', 'Exchange Gains', AccountType::OtherIncome, true],
            ['8300', 'Exchange Losses', AccountType::OtherExpense, true],
        ];

        foreach ($rows as [$no, $name, $type, $system]) {
            Account::query()->firstOrCreate(['no' => $no], [
                'name' => $name,
                'account_type' => $type,
                'is_system' => $system,
                'is_active' => true,
                'used_all_user' => true,
            ]);
        }

        $id = fn (string $no): int => Account::query()->where('no', $no)->value('id');

        app(Preferensi::class)->setMany([
            PreferensiKey::ReceivableAccount->value => $id('1200'),
            PreferensiKey::CustomerDownPaymentAccount->value => $id('2210'),
            PreferensiKey::SalesDiscountAccount->value => $id('4300'),
            PreferensiKey::PayableAccount->value => $id('2100'),
            PreferensiKey::VendorDownPaymentAccount->value => $id('1410'),
            PreferensiKey::CostOfSalesAccount->value => $id('5100'),
            PreferensiKey::InventoryAccount->value => $id('1300'),
            PreferensiKey::GoodsInTransitAccount->value => $id('1310'),
            PreferensiKey::RoundingAccount->value => $id('7200'),
            PreferensiKey::ExchangeGainAccount->value => $id('7300'),
            PreferensiKey::ExchangeLossAccount->value => $id('8300'),
            PreferensiKey::GiroReceivableAccount->value => $id('1105'),
            PreferensiKey::GiroPayableAccount->value => $id('2105'),
            PreferensiKey::TemporaryPaymentAccount->value => $id('1103'),
            PreferensiKey::SalaryExpenseAccount->value => $id('6100'),
            PreferensiKey::SalaryPayableAccount->value => $id('2230'),
            PreferensiKey::Pph21PayableAccount->value => $id('2220'),
            PreferensiKey::BpjsPayableAccount->value => $id('2240'),
            PreferensiKey::BpjsExpenseAccount->value => $id('6110'),
        ]);
    }
}
