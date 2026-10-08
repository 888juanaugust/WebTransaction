<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Domain\Reports\TradeReports;
use Filament\Forms\Components\Select;

/** A customer's or vendor's statement: brought forward, every document in the period, the running balance owed. */
abstract class PartyStatement extends ReportPage
{
    /** 'customer' or 'vendor' */
    abstract protected static function party(): string;

    /** @return array<int, string> */
    abstract protected static function parties(): array;

    abstract protected static function partyLabel(): string;

    abstract protected static function chargeLabel(): string;

    abstract protected static function paymentLabel(): string;

    protected function defaultFilters(): array
    {
        return parent::defaultFilters() + ['party_id' => null, 'currency_id' => null];
    }

    protected function extraFilters(): array
    {
        return [
            Select::make('party_id')->label(static::partyLabel())->options(fn () => static::parties())->searchable()->native(false)->live(),
            static::currencyFilter(),
        ];
    }

    protected function rows(): array
    {
        $partyId = (int) ($this->filters['party_id'] ?? 0);

        return $partyId > 0 ? TradeReports::statement(static::party(), $partyId, $this->period(), $this->reportCurrency()) : [];
    }

    protected function columns(): array
    {
        return [
            static::date('trans_date', __('Date')),
            static::text('number', __('Number')),
            static::text('kind', __('Type')),
            static::money('charge', static::chargeLabel()),
            static::money('payment', static::paymentLabel()),
            static::money('balance', __('Balance')),
        ];
    }

    protected function exportHeaders(): array
    {
        return [__('Date'), __('Number'), __('Type'), static::chargeLabel(), static::paymentLabel(), __('Balance')];
    }

    protected function exportRow(array $row): array
    {
        return [$row['trans_date'], $row['number'], $row['kind'], $this->exportMoney($row['charge']), $this->exportMoney($row['payment']), $this->exportMoney($row['balance'])];
    }
}
