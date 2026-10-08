<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemTransfers\Pages;

use App\Filament\Resources\Inventory\ItemTransfers\ItemTransferResource;
use App\Filament\Support\EditDocument;

class EditItemTransfer extends EditDocument
{
    protected static string $resource = ItemTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [ItemTransferResource::receiveAction(), ...parent::getHeaderActions()];
    }

    protected function afterSave(): void
    {
        parent::afterSave();
        $this->record->refreshStatus();
    }
}
