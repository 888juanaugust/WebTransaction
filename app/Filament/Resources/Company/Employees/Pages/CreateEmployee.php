<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\Employees\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Company\Employees\EmployeeResource;
use App\Filament\Support\CreatesNumberedRecord;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    use CreatesNumberedRecord;

    protected static string $resource = EmployeeResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::Employee;
    }
}
