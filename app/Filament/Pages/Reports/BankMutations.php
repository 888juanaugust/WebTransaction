<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\CashAndAssetReports;

/** Cash & Bank Mutations (bank-mutations): opening, in, out and closing per cash and bank account. */
class BankMutations extends ReportPage
{
    public static function reportKey(): string
    {
        return 'bank-mutations';
    }

    public static function title(): string
    {
        return __('Cash & Bank Mutations');
    }

    public static function group(): string
    {
        return 'Cash & Bank';
    }

    public static function description(): string
    {
        return __('Opening balance, money in, money out and closing balance of every cash and bank account over the period.');
    }

    protected function rows(): array
    {
        return CashAndAssetReports::bankMutations($this->period());
    }

    protected function columns(): array
    {
        return [
            static::text('no', __('No.'))->fontFamily('mono'),
            static::text('name', __('Account')),
            static::money('opening', __('Opening')),
            static::money('in', __('In')),
            static::money('out', __('Out')),
            static::money('closing', __('Closing')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('No.'), __('Account'), __('Opening'), __('In'), __('Out'), __('Closing')];
    }

    protected function exportRow(array $row): array
    {
        return [
            $row['no'],
            $row['name'],
            $row['opening'],
            $row['in'],
            $row['out'],
            $row['closing'],
        ];
    }
}
