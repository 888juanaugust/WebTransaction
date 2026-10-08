<?php

declare(strict_types=1);

namespace App\Filament\Resources\Budgeting\Budgets\Pages;

use App\Filament\Resources\Budgeting\Budgets\BudgetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBudgets extends ListRecords
{
    protected static string $resource = BudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New budget'))];
    }
}
