<?php

declare(strict_types=1);

namespace App\Modules\Tax;

use App\Domain\Access\MenuKey;
use App\Domain\Pengaturan\PreferensiKey;
use App\Domain\Tax\TaxInvoiceBlocker;
use App\Models\Tax\TaxFiling;
use App\Models\Tax\TaxFilingDocument;
use App\Models\Tax\TaxInvoiceMail;
use App\Models\Tax\VatReturnRecord;
use App\Modules\BaseModule;
use App\Modules\ModuleContext;

/** Tax filings: the VAT export files and the VAT return. Switched by the Tax feature. */
final class TaxModule extends BaseModule
{
    public static function key(): string
    {
        return 'tax';
    }

    public static function feature(): ?PreferensiKey
    {
        return PreferensiKey::Tax;
    }

    public static function menuKeys(): array
    {
        return [MenuKey::ETaxInvoiceExport, MenuKey::EmailTaxInvoice, MenuKey::LegacyETaxExport, MenuKey::VATReturn];
    }

    public static function morphMap(): array
    {
        return ['tax_filing' => TaxFiling::class, 'tax_filing_document' => TaxFilingDocument::class, 'vat_return' => VatReturnRecord::class, 'tax_invoice_mail' => TaxInvoiceMail::class];
    }

    public static function boot(ModuleContext $context): void
    {
        // A document whose tax invoice serial is recorded stays as it was reported.
        $context->guard->addBlocker(new TaxInvoiceBlocker);
    }
}
