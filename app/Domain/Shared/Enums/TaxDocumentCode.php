<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

use Filament\Support\Contracts\HasLabel;

/** The tax-invoice transaction kind of a party; sales and purchases offer different sets. */
enum TaxDocumentCode: string implements HasLabel
{
    case TaxInvoice = 'tax_invoice';
    case SpecificDocument = 'specific_document';
    case Export = 'export';
    case Aggregated = 'aggregated';
    case Import = 'import';
    case Domestic = 'domestic';
    case NotCredited = 'not_credited';

    public function getLabel(): string
    {
        return match ($this) {
            self::TaxInvoice => __('Tax invoice'),
            self::SpecificDocument => __('Specific document'),
            self::Export => __('Export'),
            self::Aggregated => __('Aggregated (digunggung)'),
            self::Import => __('Import'),
            self::Domestic => __('Domestic acquisition'),
            self::NotCredited => __('Not credited'),
        };
    }

    /** @return list<self> */
    public static function forCustomers(): array
    {
        return [self::TaxInvoice, self::SpecificDocument, self::Export, self::Aggregated];
    }

    /** @return list<self> */
    public static function forVendors(): array
    {
        return [self::TaxInvoice, self::Import, self::Domestic, self::NotCredited, self::Aggregated];
    }

    /** @param  list<self>  $cases */
    public static function options(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
