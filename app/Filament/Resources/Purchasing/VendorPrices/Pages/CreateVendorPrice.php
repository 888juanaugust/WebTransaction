<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorPrices\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\VendorPrices\VendorPriceResource;
use App\Filament\Support\CreateDocument;

class CreateVendorPrice extends CreateDocument
{
    protected static string $resource = VendorPriceResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::VendorPrice;
    }
}
