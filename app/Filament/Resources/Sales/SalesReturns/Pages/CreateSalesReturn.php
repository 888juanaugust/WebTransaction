<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SalesReturns\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SalesReturns\SalesReturnResource;
use App\Filament\Support\CreateDocument;

class CreateSalesReturn extends CreateDocument
{
    protected static string $resource = SalesReturnResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::SalesReturn;
    }
}
