<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\PurchaseReturns\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\PurchaseReturns\PurchaseReturnResource;
use App\Filament\Support\CreateDocument;

class CreatePurchaseReturn extends CreateDocument
{
    protected static string $resource = PurchaseReturnResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::PurchaseReturn;
    }
}
