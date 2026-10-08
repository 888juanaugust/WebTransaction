<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\Vendors\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Purchasing\Vendors\VendorResource;
use App\Filament\Support\CreatesNumberedRecord;
use Filament\Resources\Pages\CreateRecord;

class CreateVendor extends CreateRecord
{
    use CreatesNumberedRecord;

    protected static string $resource = VendorResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::Vendor;
    }
}
