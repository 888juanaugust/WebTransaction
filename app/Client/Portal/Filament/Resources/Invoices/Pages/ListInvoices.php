<?php

declare(strict_types=1);

namespace App\Client\Portal\Filament\Resources\Invoices\Pages;

use App\Client\Portal\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
