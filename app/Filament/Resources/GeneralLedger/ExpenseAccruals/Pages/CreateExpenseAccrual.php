<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\ExpenseAccruals\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\GeneralLedger\ExpenseAccruals\ExpenseAccrualResource;
use App\Filament\Support\CreateDocument;

class CreateExpenseAccrual extends CreateDocument
{
    protected static string $resource = ExpenseAccrualResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::ExpenseAccrual;
    }
}
