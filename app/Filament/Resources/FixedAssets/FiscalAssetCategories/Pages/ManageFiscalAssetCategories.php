<?php

declare(strict_types=1);

namespace App\Filament\Resources\FixedAssets\FiscalAssetCategories\Pages;

use App\Filament\Resources\FixedAssets\FiscalAssetCategories\FiscalAssetCategoryResource;
use App\Filament\Support\ManageMaster;

class ManageFiscalAssetCategories extends ManageMaster
{
    protected static string $resource = FiscalAssetCategoryResource::class;
}
