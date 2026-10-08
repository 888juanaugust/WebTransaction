<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SellingPriceAdjustments\Pages;

use App\Filament\Resources\Sales\SellingPriceAdjustments\SellingPriceAdjustmentResource;
use App\Filament\Support\ListDocuments;

class ListSellingPriceAdjustments extends ListDocuments
{
    protected static string $resource = SellingPriceAdjustmentResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
