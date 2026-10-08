<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\TradeReports;

/** Purchases by Vendor (purchases-by-vendor). */
class PurchasesByVendor extends ReportPage
{
    public static function reportKey(): string
    {
        return 'purchases-by-vendor';
    }

    public static function title(): string
    {
        return __('Purchases by Vendor');
    }

    public static function group(): string
    {
        return 'Purchasing';
    }

    public static function description(): string
    {
        return __('Invoiced purchases per vendor in the period: invoices, quantity, amount, VAT and total.');
    }

    protected function rows(): array
    {
        return TradeReports::purchasesBy('party', $this->period());
    }

    protected function columns(): array
    {
        return [
            static::text('name', __('Vendor')),
            static::text('invoices', __('Invoices'))->alignEnd(),
            static::quantity('quantity', __('Quantity')),
            static::money('amount', __('Amount')),
            static::money('tax', __('VAT')),
            static::money('total', __('Total')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Vendor'), __('Invoices'), __('Quantity'), __('Amount'), __('VAT'), __('Total')];
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
