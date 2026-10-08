<?php

declare(strict_types=1);

namespace App\Filament\Resources\Company\RecurringTransactions\Pages;

use App\Filament\Resources\Company\RecurringTransactions\RecurringTransactionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecurringTransaction extends EditRecord
{
    protected static string $resource = RecurringTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
