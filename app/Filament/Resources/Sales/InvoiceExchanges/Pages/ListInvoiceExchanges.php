<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\InvoiceExchanges\Pages;

use App\Filament\Resources\Sales\InvoiceExchanges\InvoiceExchangeResource;
use App\Filament\Support\ListDocuments;

class ListInvoiceExchanges extends ListDocuments
{
    protected static string $resource = InvoiceExchangeResource::class;

    protected function statuses(): array
    {
        return ['pending' => __('Pending'), 'processed' => __('Collected')];
    }
}
