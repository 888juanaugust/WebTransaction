<?php

declare(strict_types=1);

namespace App\Filament\Resources\GeneralLedger\ExpenseAccruals\Pages;

use App\Filament\Resources\GeneralLedger\ExpenseAccruals\ExpenseAccrualResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseAccruals extends ListRecords
{
    protected static string $resource = ExpenseAccrualResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New expense accrual'))];
    }
}
