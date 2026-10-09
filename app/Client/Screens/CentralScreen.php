<?php

declare(strict_types=1);

namespace App\Client\Screens;

use App\Domain\Access\ScreenKey;
use App\Domain\Access\ScreenKind;
use App\Filament\Modul;

/** Central's own screens. A value is stored in access rights, so it never changes once in use. */
enum CentralScreen: string implements ScreenKey
{
    case Teams = 'client__teams';
    case OrderApprovals = 'client__order-approvals';
    case PriceList = 'client__price-list';
    case CustomerPrices = 'client__customer-prices';
    case SettlementClaims = 'client__settlement-claims';
    case ExpenseClaims = 'client__expense-claims';
    case ReturnClaims = 'client__return-claims';
    case Collections = 'client__collections';

    public function modul(): Modul
    {
        return match ($this) {
            self::Teams, self::OrderApprovals, self::CustomerPrices, self::SettlementClaims, self::ReturnClaims, self::Collections => Modul::Sales,
            self::PriceList => Modul::Inventory,
            self::ExpenseClaims => Modul::CashBank,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Teams => __('Customer Teams'),
            self::OrderApprovals => __('Order Approvals'),
            self::PriceList => __('Price List'),
            self::CustomerPrices => __('Customer Prices'),
            self::SettlementClaims => __('Settlement Claims'),
            self::ExpenseClaims => __('Expense Claims'),
            self::ReturnClaims => __('Return Claims'),
            self::Collections => __('Collections'),
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::OrderApprovals => 15,
            self::SettlementClaims => 20,
            self::ReturnClaims => 25,
            self::Collections => 22,
            self::ExpenseClaims => 30,
            self::PriceList => 85,
            self::CustomerPrices => 505,
            self::Teams => 510,
        };
    }

    public function kind(): ScreenKind
    {
        return match ($this) {
            self::OrderApprovals, self::PriceList, self::SettlementClaims, self::ExpenseClaims, self::ReturnClaims, self::Collections => ScreenKind::Work,
            self::Teams, self::CustomerPrices => ScreenKind::Setup,
        };
    }

    public function isReplicated(): bool
    {
        return true;
    }

    public function slug(): string
    {
        return str_replace('__', '/', $this->value);
    }
}
