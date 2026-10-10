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
    case CustomerTypes = 'client__customer-types';
    case SettlementClaims = 'client__settlement-claims';
    case ExpenseClaims = 'client__expense-claims';
    case ReturnClaims = 'client__return-claims';
    case Collections = 'client__collections';
    case WarehouseAccounts = 'client__warehouse-accounts';
    case Fulfilment = 'client__fulfilment';
    case BuyerAccounts = 'client__buyer-accounts';
    case Website = 'client__website';
    case SiteImages = 'client__site-images';
    case LaunchReadiness = 'client__launch-readiness';
    case Operations = 'client__operations';

    public function modul(): Modul
    {
        return match ($this) {
            self::Teams, self::OrderApprovals, self::CustomerPrices, self::CustomerTypes, self::SettlementClaims, self::ReturnClaims, self::Collections, self::BuyerAccounts => Modul::Sales,
            self::PriceList, self::WarehouseAccounts, self::Fulfilment => Modul::Inventory,
            self::ExpenseClaims => Modul::CashBank,
            self::Website, self::SiteImages => Modul::Company,
            self::LaunchReadiness, self::Operations => Modul::Settings,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Teams => __('Customer Teams'),
            self::OrderApprovals => __('Order Approvals'),
            self::PriceList => __('Price List'),
            self::CustomerPrices => __('Customer Prices'),
            self::CustomerTypes => __('Customer Types'),
            self::SettlementClaims => __('Settlement Claims'),
            self::ExpenseClaims => __('Expense Claims'),
            self::ReturnClaims => __('Return Claims'),
            self::Collections => __('Collections'),
            self::WarehouseAccounts => __('Warehouse Accounts'),
            self::Fulfilment => __('Fulfilment'),
            self::BuyerAccounts => __('Buyer Accounts'),
            self::Website => __('Website'),
            self::SiteImages => __('Website Images'),
            self::LaunchReadiness => __('Launch Readiness'),
            self::Operations => __('Operations'),
        };
    }

    public function sort(): int
    {
        return match ($this) {
            self::OrderApprovals => 15,
            self::SettlementClaims => 20,
            self::ReturnClaims => 25,
            self::Collections => 22,
            self::Fulfilment => 20,
            self::WarehouseAccounts => 520,
            self::Website => 530,
            self::SiteImages => 535,
            self::LaunchReadiness => 41,
            self::Operations => 40,
            self::BuyerAccounts => 515,
            self::ExpenseClaims => 30,
            self::PriceList => 85,
            self::CustomerPrices => 505,
            self::CustomerTypes => 506,
            self::Teams => 510,
        };
    }

    public function kind(): ScreenKind
    {
        return match ($this) {
            self::OrderApprovals, self::PriceList, self::SettlementClaims, self::ExpenseClaims, self::ReturnClaims, self::Collections, self::Fulfilment => ScreenKind::Work,
            self::Operations => ScreenKind::Tool,
            self::Teams, self::CustomerPrices, self::CustomerTypes, self::WarehouseAccounts, self::BuyerAccounts, self::Website, self::SiteImages, self::LaunchReadiness => ScreenKind::Setup,
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
