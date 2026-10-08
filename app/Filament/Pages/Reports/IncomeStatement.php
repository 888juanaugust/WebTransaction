<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\FinancialStatements;

/** Income Statement (R-02): the period's result, section by section. */
class IncomeStatement extends ReportPage
{
    protected function yearToDate(): bool
    {
        return true;
    }

    protected function usesTags(): bool
    {
        return true;
    }

    public static function reportKey(): string
    {
        return 'income-statement';
    }

    public static function title(): string
    {
        return __('Income Statement');
    }

    public static function group(): string
    {
        return 'Financial';
    }

    public static function description(): string
    {
        return __('Revenue, cost of sales, expenses and the net income of the period, per branch when asked.');
    }

    protected function rows(): array
    {
        return FinancialStatements::incomeStatement($this->period());
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
