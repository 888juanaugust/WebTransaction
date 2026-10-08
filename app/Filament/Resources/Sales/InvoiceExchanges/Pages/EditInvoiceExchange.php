<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\InvoiceExchanges\Pages;

use App\Filament\Resources\Sales\InvoiceExchanges\InvoiceExchangeResource;
use App\Filament\Support\EditDocument;

class EditInvoiceExchange extends EditDocument
{
    protected static string $resource = InvoiceExchangeResource::class;
}
