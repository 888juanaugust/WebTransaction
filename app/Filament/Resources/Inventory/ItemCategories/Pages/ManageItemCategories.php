<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventory\ItemCategories\Pages;

use App\Filament\Resources\Inventory\ItemCategories\ItemCategoryResource;
use App\Filament\Support\ManageMaster;

class ManageItemCategories extends ManageMaster
{
    protected static string $resource = ItemCategoryResource::class;
}
