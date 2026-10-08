<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\RecurringTransactions\Pages;

use App\Filament\Resources\Company\RecurringTransactions\RecurringTransactionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRecurringTransaction extends CreateRecord
{
    protected static string $resource = RecurringTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
