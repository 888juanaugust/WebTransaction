<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\AssetCategories\Pages;

use App\Filament\Resources\FixedAssets\AssetCategories\AssetCategoryResource;
use App\Filament\Support\ManageMaster;

class ManageAssetCategories extends ManageMaster
{
    protected static string $resource = AssetCategoryResource::class;
}
