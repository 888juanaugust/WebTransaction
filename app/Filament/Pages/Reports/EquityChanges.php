<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\FinancialStatements;

/** Statement of Changes in Equity (R-06): opening equity to closing equity. */
class EquityChanges extends ReportPage
{
    protected function yearToDate(): bool
    {
        return true;
    }

    public static function reportKey(): string
    {
        return 'equity-changes';
    }

    public static function title(): string
    {
        return __('Statement of Changes in Equity');
    }

    public static function group(): string
    {
        return 'Financial';
    }

    public static function description(): string
    {
        return "Equity at the start, capital movements, the period's income, equity at the end.";
    }

    protected function rows(): array
    {
        return FinancialStatements::equityChanges($this->period());
    }

    protected function columns(): array
    {
        return [
            static::text('name', __('Item')),
            static::money('amount', __('Amount')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Item'), __('Amount')];
    }

    protected function exportRow(array $row): array
    {
        return [$row['name'] ?? '', $row['amount'] ?? null];
    }
}
