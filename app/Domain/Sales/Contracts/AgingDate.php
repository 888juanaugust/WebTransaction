<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contracts;

use App\Models\Sales\SalesInvoice;
use Carbon\CarbonInterface;

/**
 * The day a receivable starts to age under the invoice-date basis. The base
 * counts from the invoice's date (InvoiceDateAging); an installation may
 * bind its own day, which the credit check, the aging report, the invoice
 * list and the due date then share.
 */
interface AgingDate
{
    public function issued(SalesInvoice $invoice): CarbonInterface;

    /** The same day as a SQL expression over sales_invoices, for MIN() and WHERE. */
    public function issuedColumn(): string;
}
