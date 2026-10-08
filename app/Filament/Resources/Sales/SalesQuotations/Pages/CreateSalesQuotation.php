<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesQuotations\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SalesQuotations\SalesQuotationResource;
use App\Filament\Support\CreateDocument;

class CreateSalesQuotation extends CreateDocument
{
    protected static string $resource = SalesQuotationResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::SalesQuotation;
    }
}
