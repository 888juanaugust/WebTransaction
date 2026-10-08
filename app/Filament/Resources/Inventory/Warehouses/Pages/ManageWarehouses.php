<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\Warehouses\Pages;

use App\Filament\Resources\Inventory\Warehouses\WarehouseResource;
use App\Filament\Support\ManageMaster;

class ManageWarehouses extends ManageMaster
{
    protected static string $resource = WarehouseResource::class;
}
