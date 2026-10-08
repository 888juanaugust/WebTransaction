<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\TradeReports;

/** Sales by Item (sales-by-item). */
class SalesByItem extends ReportPage
{
    public static function reportKey(): string
    {
        return 'sales-by-item';
    }

    public static function title(): string
    {
        return __('Sales by Item');
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return __('Invoiced sales per item in the period: invoices, quantity, amount, VAT and total.');
    }

    protected function rows(): array
    {
        return TradeReports::salesBy('item', $this->period());
    }

    protected function columns(): array
    {
        return [
            static::text('name', __('Item')),
            static::text('invoices', __('Invoices'))->alignEnd(),
            static::quantity('quantity', __('Quantity')),
            static::money('amount', __('Amount')),
            static::money('tax', __('VAT')),
            static::money('total', __('Total')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Item'), __('Invoices'), __('Quantity'), __('Amount'), __('VAT'), __('Total')];
    }

    protected function exportRow(array $row): array
    {
        return [
            $row['name'],
            $row['invoices'],
            $row['quantity'],
            $row['amount'],
            $row['tax'],
            $row['total'],
        ];
    }
}
