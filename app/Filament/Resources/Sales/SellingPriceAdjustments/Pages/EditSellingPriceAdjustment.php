<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SellingPriceAdjustments\Pages;

use App\Filament\Resources\Sales\SellingPriceAdjustments\SellingPriceAdjustmentResource;
use App\Filament\Support\EditDocument;

class EditSellingPriceAdjustment extends EditDocument
{
    protected static string $resource = SellingPriceAdjustmentResource::class;
}
