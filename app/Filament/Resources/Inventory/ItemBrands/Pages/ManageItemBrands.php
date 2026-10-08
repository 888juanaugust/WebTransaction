<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemBrands\Pages;

use App\Filament\Resources\Inventory\ItemBrands\ItemBrandResource;
use App\Filament\Support\ManageMaster;

class ManageItemBrands extends ManageMaster
{
    protected static string $resource = ItemBrandResource::class;
}
