<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tax;

use App\Domain\Access\MenuKey;
use App\Models\Tax\TaxFiling;
use Filament\Support\Icons\Heroicon;

/**
 * Legacy e-Tax Export: the same period and invoices as the e-Tax Invoice
 * Export, written to the older CSV layout so a filing made under it can be
 * reproduced.
 */
class LegacyETaxExport extends ETaxInvoiceExport
{
    protected string $view = 'filament.pages.tax.legacy-e-tax-export';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function menuKey(): MenuKey
    {
        return MenuKey::LegacyETaxExport;
    }

    protected function format(): string
    {
        return TaxFiling::LEGACY;
    }
}
