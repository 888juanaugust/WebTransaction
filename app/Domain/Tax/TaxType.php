<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use Filament\Support\Contracts\HasLabel;

/** The tax types of the Tax Codes screen, as the standard lists them. */
enum TaxType: string implements HasLabel
{
    case Vat = 'vat';
    case LuxuryTax = 'luxury_tax';
    case IncomeTax4_2 = 'income_tax_4_2';
    case IncomeTax15 = 'income_tax_15';
    case IncomeTax21 = 'income_tax_21';
    case IncomeTax22 = 'income_tax_22';
    case IncomeTax23 = 'income_tax_23';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vat => __('Value Added Tax (PPN)'),
            self::LuxuryTax => __('Luxury Goods Tax (PPnBM)'),
            self::IncomeTax4_2 => __('Income Tax Art. 4(2)'),
            self::IncomeTax15 => __('Income Tax Art. 15'),
            self::IncomeTax21 => __('Income Tax Art. 21'),
            self::IncomeTax22 => __('Income Tax Art. 22'),
            self::IncomeTax23 => __('Income Tax Art. 23'),
        };
    }

    /** Withholding taxes reduce what is paid; they are not added to the price. */
    public function isWithholding(): bool
    {
        return ! in_array($this, [self::Vat, self::LuxuryTax], true);
    }
}
