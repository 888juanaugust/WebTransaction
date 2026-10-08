<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorClaims\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\VendorClaims\VendorClaimResource;
use App\Filament\Support\CreateDocument;

class CreateVendorClaim extends CreateDocument
{
    protected static string $resource = VendorClaimResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::VendorClaim;
    }
}
