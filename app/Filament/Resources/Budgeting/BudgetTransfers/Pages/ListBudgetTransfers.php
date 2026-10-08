<?php

declare(strict_types=1);

namespace App\Filament\Resources\Budgeting\BudgetTransfers\Pages;

use App\Filament\Resources\Budgeting\BudgetTransfers\BudgetTransferResource;
use App\Filament\Support\ListDocuments;

class ListBudgetTransfers extends ListDocuments
{
    protected static string $resource = BudgetTransferResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
