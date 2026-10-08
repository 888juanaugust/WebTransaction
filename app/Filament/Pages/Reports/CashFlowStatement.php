<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\FinancialStatements;

/** Cash Flow Statement (R-05): the period's cash movements by activity. */
class CashFlowStatement extends ReportPage
{
    protected function yearToDate(): bool
    {
        return true;
    }

    public static function reportKey(): string
    {
        return 'cash-flow';
    }

    public static function title(): string
    {
        return __('Cash Flow Statement');
    }

    public static function group(): string
    {
        return 'Financial';
    }

    public static function description(): string
    {
        return __('Cash in and out by operating, investing and financing activities, from the counter-accounts of every cash posting.');
    }

    protected function rows(): array
    {
        return FinancialStatements::cashFlow($this->period());
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
        return [$row['name'] ?? '', ($row['is_heading'] ?? false) ? null : ($row['amount'] ?? null)];
    }
}
