<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\Units\Pages;

use App\Filament\Resources\Inventory\Units\UnitResource;
use App\Filament\Support\ManageMaster;

class ManageUnits extends ManageMaster
{
    protected static string $resource = UnitResource::class;
}
