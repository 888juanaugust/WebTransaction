<?php

declare(strict_types=1);

namespace App\Filament\Resources\Budgeting\BudgetTransfers\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Budgeting\BudgetTransfers\BudgetTransferResource;
use App\Filament\Support\CreateDocument;

class CreateBudgetTransfer extends CreateDocument
{
    protected static string $resource = BudgetTransferResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::BudgetTransfer;
    }
}
