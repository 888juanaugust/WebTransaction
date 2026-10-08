<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\InvoiceExchanges\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\InvoiceExchanges\InvoiceExchangeResource;
use App\Filament\Support\CreateDocument;

class CreateInvoiceExchange extends CreateDocument
{
    protected static string $resource = InvoiceExchangeResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::InvoiceExchange;
    }
}
