<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\AgingBuckets;
use App\Domain\Reports\TradeReports;
use Filament\Forms\Components\Select;

/** Receivable Aging (receivable-aging). */
class ReceivableAging extends ReportPage
{
    public static function reportKey(): string
    {
        return 'receivable-aging';
    }

    public static function title(): string
    {
        return __('Receivable Aging');
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return "Open receivables per customer by age at the period's end, in the buckets Preferences set (current, 1–30, 31–60, 61–90 and over 90 days to start).";
    }

    protected function defaultFilters(): array
    {
        return parent::defaultFilters() + ['basis' => AgingBuckets::defaultBasis(), 'currency_id' => null];
    }

    protected function extraFilters(): array
    {
        return [
            Select::make('basis')
                ->label(__('Age from'))
                ->options(['invoice_date' => __('Invoice date'), 'due_date' => __('Due date')])
                ->default(fn () => AgingBuckets::defaultBasis())
                ->native(false)
                ->live(),
            static::currencyFilter(),
        ];
    }

    protected function rows(): array
    {
        return TradeReports::receivableAging($this->period(), $this->filters['basis'] ?? AgingBuckets::defaultBasis(), $this->reportCurrency());
    }

    protected function columns(): array
    {
        return [
            static::text('name', __('Customer')),
            static::text('invoices', __('Open'))->alignEnd(),
            ...array_map(fn (array $bucket) => static::money($bucket['key'], $bucket['label']), AgingBuckets::all()),
            static::money('total', __('Total')),
            static::text('oldest_days', __('Oldest (days)'))->alignEnd(),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Customer'), __('Open'), ...array_column(AgingBuckets::all(), 'label'), __('Total'), __('Oldest (days)')];
    }

    protected function exportRow(array $row): array
    {
        return [$row['name'], $row['invoices'], ...array_map(fn (array $bucket) => $this->exportMoney($row[$bucket['key']]), AgingBuckets::all()), $this->exportMoney($row['total']), $row['oldest_days']];
    }
}
