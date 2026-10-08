<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sales\SellingPriceAdjustments\Pages;

use App\Domain\Numbering\TransactionType;
use App\Filament\Resources\Sales\SellingPriceAdjustments\SellingPriceAdjustmentResource;
use App\Filament\Support\CreateDocument;

class CreateSellingPriceAdjustment extends CreateDocument
{
    protected static string $resource = SellingPriceAdjustmentResource::class;

    protected function transactionType(): TransactionType
    {
        return TransactionType::PriceAdjustment;
    }
}
