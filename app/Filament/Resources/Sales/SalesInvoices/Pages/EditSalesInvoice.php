<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesInvoices\Pages;

use App\Filament\Resources\Sales\SalesInvoices\SalesInvoiceResource;
use App\Filament\Support\EditDocument;

class EditSalesInvoice extends EditDocument
{
    protected static string $resource = SalesInvoiceResource::class;
}
