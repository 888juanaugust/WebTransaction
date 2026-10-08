<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\InventoryAdjustments\Pages;

use App\Filament\Resources\Inventory\InventoryAdjustments\InventoryAdjustmentResource;
use App\Filament\Support\EditDocument;

class EditInventoryAdjustment extends EditDocument
{
    protected static string $resource = InventoryAdjustmentResource::class;
}
