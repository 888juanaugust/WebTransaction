<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\CustomerCategories\Pages;

use App\Filament\Resources\Sales\CustomerCategories\CustomerCategoryResource;
use App\Filament\Support\ManageMaster;

class ManageCustomerCategories extends ManageMaster
{
    protected static string $resource = CustomerCategoryResource::class;
}
