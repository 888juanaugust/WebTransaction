<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\PriceCategories\Pages;

use App\Filament\Resources\Sales\PriceCategories\PriceCategoryResource;
use App\Filament\Support\ManageMaster;

class ManagePriceCategories extends ManageMaster
{
    protected static string $resource = PriceCategoryResource::class;
}
