<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemTransfers\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Inventory\ItemTransfers\ItemTransferResource;
use App\Filament\Support\CreateDocument;

class CreateItemTransfer extends CreateDocument
{
    protected static string $resource = ItemTransferResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::ItemTransfer;
    }

    protected function afterCreate(): void
    {
        parent::afterCreate();
        $this->record->refreshStatus();
    }
}
