<?php

declare(strict_types=1);

namespace App\Filament\Resources\Budgeting\BudgetTransfers\Pages;

use App\Filament\Resources\Budgeting\BudgetTransfers\BudgetTransferResource;
use App\Filament\Support\EditDocument;

class EditBudgetTransfer extends EditDocument
{
    protected static string $resource = BudgetTransferResource::class;
}
