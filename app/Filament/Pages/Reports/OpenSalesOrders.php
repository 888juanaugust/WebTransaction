<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\TradeReports;

/** Open Sales Orders (open-sales-orders). */
class OpenSalesOrders extends ReportPage
{
    public static function reportKey(): string
    {
        return 'open-sales-orders';
    }

    public static function title(): string
    {
        return __('Open Sales Orders');
    }

    public static function group(): string
    {
        return 'Sales';
    }

    public static function description(): string
    {
        return __('Order lines not yet fully delivered or invoiced, with what is left and its value.');
    }

    protected function rows(): array
    {
        return TradeReports::openSalesOrders($this->period());
    }

    protected function columns(): array
    {
        return [
            static::text('number', __('Order'))->fontFamily('mono'),
            static::date('trans_date', __('Date')),
            static::text('party', __('Customer')),
            static::text('item', __('Item')),
            static::quantity('ordered', __('Ordered')),
            static::quantity('processed', __('Processed')),
            static::quantity('remaining', __('Remaining')),
            static::money('value', __('Open value')),
            static::text('status', __('Status')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Order'), __('Date'), __('Customer'), __('Item'), __('Ordered'), __('Processed'), __('Remaining'), __('Open value'), __('Status')];
    }

    protected function exportRow(array $row): array
    {
        return [
            $row['number'],
            $row['trans_date'],
            $row['party'],
            $row['item'],
            $row['ordered'],
            $row['processed'],
            $row['remaining'],
            $row['value'],
            $row['status'],
        ];
    }
}
