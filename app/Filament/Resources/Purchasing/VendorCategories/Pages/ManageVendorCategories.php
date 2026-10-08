<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorCategories\Pages;

use App\Filament\Resources\Purchasing\VendorCategories\VendorCategoryResource;
use App\Filament\Support\ManageMaster;

class ManageVendorCategories extends ManageMaster
{
    protected static string $resource = VendorCategoryResource::class;
}
