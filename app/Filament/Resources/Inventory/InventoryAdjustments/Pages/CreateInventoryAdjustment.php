<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\InventoryAdjustments\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Inventory\InventoryAdjustments\InventoryAdjustmentResource;
use App\Filament\Support\CreateDocument;

class CreateInventoryAdjustment extends CreateDocument
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::InventoryAdjustment;
    }
}
