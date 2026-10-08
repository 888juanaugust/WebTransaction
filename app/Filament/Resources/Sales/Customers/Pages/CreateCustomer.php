<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\Customers\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Filament\Support\CreatesNumberedRecord;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    use CreatesNumberedRecord;

    protected static string $resource = CustomerResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::Customer;
    }
}
