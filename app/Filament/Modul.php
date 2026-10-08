<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The ten modules of the sidebar, in the order the standard shows them.
 * Every screen (App\Domain\Access\MenuKey) belongs to exactly one.
 */
enum Modul: string implements HasIcon, HasLabel
{
    case Settings = 'settings';
    case Company = 'company';
    case GeneralLedger = 'general-ledger';
    case CashBank = 'cash-bank';
    case Sales = 'sales';
    case Purchasing = 'purchasing';
    case Inventory = 'inventory';
    case FixedAssets = 'fixed-assets';
    case Tax = 'tax';
    case Reports = 'reports';

    public function getLabel(): string
    {
        return __('menu.modules.'.$this->name);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Settings => 'heroicon-o-cog-6-tooth',
            self::Company => 'heroicon-o-building-office-2',
            self::GeneralLedger => 'heroicon-o-book-open',
            self::CashBank => 'heroicon-o-banknotes',
            self::Sales => 'heroicon-o-shopping-cart',
            self::Purchasing => 'heroicon-o-truck',
            self::Inventory => 'heroicon-o-cube',
            self::FixedAssets => 'heroicon-o-building-library',
            self::Tax => 'heroicon-o-document-text',
            self::Reports => 'heroicon-o-chart-bar',
        };
    }

    /** Position of the module in the sidebar. */
    public function order(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
