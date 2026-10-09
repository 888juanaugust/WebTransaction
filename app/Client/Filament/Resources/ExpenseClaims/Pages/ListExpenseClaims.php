<?php

declare(strict_types=1);

namespace App\Client\Filament\Resources\ExpenseClaims\Pages;

use App\Client\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseClaims extends ListRecords
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('File an expense claim'))];
    }
}
