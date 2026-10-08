<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\FinancialStatements;

/** Balance Sheet (R-01): the ledger's position as at the period's end. */
class BalanceSheet extends ReportPage
{
    public static function reportKey(): string
    {
        return 'balance-sheet';
    }

    public static function title(): string
    {
        return __('Balance Sheet');
    }

    public static function group(): string
    {
        return 'Financial';
    }

    public static function description(): string
    {
        return __('Assets, liabilities and equity as at the end of the period, current and non-current, with the income to date.');
    }

    protected function rows(): array
    {
        return FinancialStatements::balanceSheet($this->period());
    }

    protected function columns(): array
    {
        return [
            static::text('no', __('No.')),
            static::text('name', __('Account'))->extraCellAttributes(fn ($record) => ['style' => 'padding-left: '.((($record['level'] ?? 0) * 16) + 12).'px']),
            static::money('amount', __('Amount')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('No.'), __('Account'), __('Amount')];
    }

    protected function exportRow(array $row): array
    {
        return [$row['no'] ?? '', $row['name'] ?? '', ($row['is_heading'] ?? false) ? null : ($row['amount'] ?? null)];
    }
}
