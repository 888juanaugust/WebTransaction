<?php

declare(strict_types=1);

namespace App\Filament\Resources\Purchasing\VendorPrices\Pages;

use App\Filament\Resources\Purchasing\VendorPrices\VendorPriceResource;
use App\Filament\Support\EditDocument;

class EditVendorPrice extends EditDocument
{
    protected static string $resource = VendorPriceResource::class;
}
