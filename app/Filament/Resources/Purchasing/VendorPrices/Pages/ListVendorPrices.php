<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorPrices\Pages;

use App\Filament\Resources\Purchasing\VendorPrices\VendorPriceResource;
use App\Filament\Support\ListDocuments;

class ListVendorPrices extends ListDocuments
{
    protected static string $resource = VendorPriceResource::class;

    public function getTabs(): array
    {
        return [];
    }
}
