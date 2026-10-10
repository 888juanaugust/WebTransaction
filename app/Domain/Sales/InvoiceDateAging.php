<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Contracts\AgingDate;
use App\Models\Sales\SalesInvoice;
use Carbon\CarbonInterface;

/** The base's clock: an invoice ages from its own date. */
final class InvoiceDateAging implements AgingDate
{
    public function issued(SalesInvoice $invoice): CarbonInterface
    {
        return $invoice->trans_date;
    }

    public function issuedColumn(): string
    {
        return 'trans_date';
    }
}
