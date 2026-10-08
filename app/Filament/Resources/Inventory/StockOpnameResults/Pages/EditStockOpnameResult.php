<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\StockOpnameResults\Pages;

use App\Domain\Inventory\OpnameApprover;
use App\Filament\Resources\Inventory\StockOpnameResults\StockOpnameResultResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStockOpnameResult extends EditRecord
{
    protected static string $resource = StockOpnameResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StockOpnameResultResource::approveAction(),
            DeleteAction::make()->hidden(fn () => $this->record->isApproved()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_if($this->record->isApproved(), 403, 'An approved count cannot be changed.');

        return $data;
    }

    /** The system quantity is read from stock again: it is never taken from the form. */
    protected function afterSave(): void
    {
        app(OpnameApprover::class)->snapshotSystemQuantities($this->record->load('order', 'lines'));
    }
}
